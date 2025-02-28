<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Library;

use CuyZ\Valinor\Cache\ChainCache;
use CuyZ\Valinor\Cache\Compiled\CompiledPhpFileCache;
use CuyZ\Valinor\Cache\RuntimeCache;
use CuyZ\Valinor\Cache\VersionedCache;
use CuyZ\Valinor\Definition\ClassDefinition;
use CuyZ\Valinor\Definition\Repository\AttributesRepository;
use CuyZ\Valinor\Definition\Repository\Cache\CacheClassDefinitionRepository;
use CuyZ\Valinor\Definition\Repository\Cache\Compiler\ClassDefinitionCompiler;
use CuyZ\Valinor\Definition\Repository\ClassDefinitionRepository;
use CuyZ\Valinor\Definition\Repository\Reflection\CombinedAttributesRepository;
use CuyZ\Valinor\Definition\Repository\Reflection\DoctrineAnnotationsRepository;
use CuyZ\Valinor\Definition\Repository\Reflection\NativeAttributesRepository;
use CuyZ\Valinor\Definition\Repository\Reflection\ReflectionClassDefinitionRepository;
use CuyZ\Valinor\Mapper\Object\Factory\AttributeObjectBuilderFactory;
use CuyZ\Valinor\Mapper\Object\Factory\BasicObjectBuilderFactory;
use CuyZ\Valinor\Mapper\Object\Factory\ObjectBuilderFactory;
use CuyZ\Valinor\Mapper\Tree\Builder\ArrayNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\CasterNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\ClassNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\EnumNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\ErrorCatcherNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\ListNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\NodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\RootNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\ScalarNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\ShapedArrayNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\ShellVisitorNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\ValueAlteringNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Builder\VisitorNodeBuilder;
use CuyZ\Valinor\Mapper\Tree\Visitor\AggregateShellVisitor;
use CuyZ\Valinor\Mapper\Tree\Visitor\AttributeShellVisitor;
use CuyZ\Valinor\Mapper\Tree\Visitor\InterfaceShellVisitor;
use CuyZ\Valinor\Mapper\Tree\Visitor\ObjectBindingShellVisitor;
use CuyZ\Valinor\Mapper\Tree\Visitor\ShellVisitor;
use CuyZ\Valinor\Mapper\Tree\Visitor\UnionShellVisitor;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\Mapper\TreeMapperContainer;
use CuyZ\Valinor\Type\Parser\CachedParser;
use CuyZ\Valinor\Type\Parser\Factory\LexingTypeParserFactory;
use CuyZ\Valinor\Type\Parser\Factory\Specifications\HandleClassGenericSpecification;
use CuyZ\Valinor\Type\Parser\Factory\TypeParserFactory;
use CuyZ\Valinor\Type\Parser\Template\BasicTemplateParser;
use CuyZ\Valinor\Type\Parser\Template\TemplateParser;
use CuyZ\Valinor\Type\Parser\TypeParser;
use CuyZ\Valinor\Type\Resolver\Union\UnionNullNarrower;
use CuyZ\Valinor\Type\Resolver\Union\UnionObjectNarrower;
use CuyZ\Valinor\Type\Resolver\Union\UnionScalarNarrower;
use CuyZ\Valinor\Type\ScalarType;
use CuyZ\Valinor\Type\Types\ArrayType;
use CuyZ\Valinor\Type\Types\ClassType;
use CuyZ\Valinor\Type\Types\EnumType;
use CuyZ\Valinor\Type\Types\IterableType;
use CuyZ\Valinor\Type\Types\ListType;
use CuyZ\Valinor\Type\Types\NonEmptyArrayType;
use CuyZ\Valinor\Type\Types\NonEmptyListType;
use CuyZ\Valinor\Type\Types\ShapedArrayType;
use Psr\SimpleCache\CacheInterface;

use function call_user_func;

/** @internal */
final class Container
{
    /**
     * @template T of object
     * @var array<class-string<T>, T>
     */
    private array $services = [];

    /**
     * @template T of object
     * @var array<class-string<T>, callable(): T>
     */
    private array $factories;

    public function __construct(Settings $settings)
    {
        $this->factories = [
            TreeMapper::class => function (): TreeMapper {
                return new TreeMapperContainer(
                    $this->get(TypeParser::class),
                    new RootNodeBuilder($this->get(NodeBuilder::class))
                );
            },

            ShellVisitor::class => function () use ($settings): ShellVisitor {
                return new AggregateShellVisitor(
                    new UnionShellVisitor(
                        new UnionNullNarrower(
                            new UnionObjectNarrower(
                                new UnionScalarNarrower(),
                                $this->get(ClassDefinitionRepository::class),
                                $this->get(ObjectBuilderFactory::class),
                            )
                        )
                    ),
                    new InterfaceShellVisitor(
                        $settings->interfaceMapping,
                        $this->get(TypeParser::class),
                    ),
                    new AttributeShellVisitor(),
                    new ObjectBindingShellVisitor($settings->objectBinding),
                );
            },

            NodeBuilder::class => function () use ($settings): NodeBuilder {
                $listNodeBuilder = new ListNodeBuilder();
                $arrayNodeBuilder = new ArrayNodeBuilder();

                $builder = new CasterNodeBuilder([
                    EnumType::class => new EnumNodeBuilder(),
                    ListType::class => $listNodeBuilder,
                    NonEmptyListType::class => $listNodeBuilder,
                    ArrayType::class => $arrayNodeBuilder,
                    NonEmptyArrayType::class => $arrayNodeBuilder,
                    IterableType::class => $arrayNodeBuilder,
                    ShapedArrayType::class => new ShapedArrayNodeBuilder(),
                    ClassType::class => new ClassNodeBuilder(
                        $this->get(ClassDefinitionRepository::class),
                        $this->get(ObjectBuilderFactory::class),
                    ),
                    ScalarType::class => new ScalarNodeBuilder(),
                ]);

                $builder = new VisitorNodeBuilder($builder, $settings->nodeVisitors);
                $builder = new ValueAlteringNodeBuilder($builder, $settings->valueModifier);
                $builder = new ShellVisitorNodeBuilder($builder, $this->get(ShellVisitor::class));

                return new ErrorCatcherNodeBuilder($builder);
            },

            ObjectBuilderFactory::class => function (): ObjectBuilderFactory {
                return new AttributeObjectBuilderFactory(
                    new BasicObjectBuilderFactory()
                );
            },

            ClassDefinitionRepository::class => function () use ($settings): ClassDefinitionRepository {
                $repository = new ReflectionClassDefinitionRepository(
                    $this->get(TypeParserFactory::class),
                    $this->get(AttributesRepository::class),
                );

                /** @var CacheInterface<ClassDefinition> $cache */
                $cache = new CompiledPhpFileCache($settings->cacheDir, new ClassDefinitionCompiler());
                $cache = $this->wrapCache($cache);

                return new CacheClassDefinitionRepository($repository, $cache);
            },

            AttributesRepository::class => function () use ($settings): AttributesRepository {
                if (! $settings->enableLegacyDoctrineAnnotations) {
                    return new NativeAttributesRepository();
                }

                /** @infection-ignore-all */
                if (PHP_VERSION_ID >= 8_00_00) {
                    return new CombinedAttributesRepository();
                }

                /** @infection-ignore-all */
                // @codeCoverageIgnoreStart
                return new DoctrineAnnotationsRepository(); // @codeCoverageIgnoreEnd
            },

            TypeParserFactory::class => function (): TypeParserFactory {
                return new LexingTypeParserFactory(
                    $this->get(TemplateParser::class)
                );
            },

            TypeParser::class => function (): TypeParser {
                $factory = $this->get(TypeParserFactory::class);
                $parser = $factory->get(new HandleClassGenericSpecification());

                return new CachedParser($parser);
            },

            TemplateParser::class => function (): TemplateParser {
                return new BasicTemplateParser();
            },
        ];
    }

    public function treeMapper(): TreeMapper
    {
        return $this->get(TreeMapper::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $name
     * @return T
     */
    private function get(string $name): object
    {
        return $this->services[$name] ??= call_user_func($this->factories[$name]); // @phpstan-ignore-line
    }

    /**
     * @template EntryType
     *
     * @param CacheInterface<EntryType> $cache
     * @return CacheInterface<EntryType>
     */
    private function wrapCache(CacheInterface $cache): CacheInterface
    {
        return new VersionedCache(
            new ChainCache(new RuntimeCache(), $cache)
        );
    }
}
