<?php

declare(strict_types=1);

namespace Arkitect\Analyzer;

use PhpParser\Comment\Doc;
use PhpParser\ErrorHandler;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\NodeAbstract;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

class NameResolver extends NodeVisitorAbstract
{
    protected NameContext $nameContext;

    protected bool $preserveOriginalNames;

    protected bool $replaceNodes;

    protected bool $parseCustomAnnotations;

    protected PhpDocParser $phpDocParser;

    protected Lexer $phpDocLexer;

    /**
     * Constructs a name resolution visitor.
     *
     * Options:
     *  * preserveOriginalNames (default false): An "originalName" attribute will be added to
     *    all name nodes that underwent resolution.
     *  * replaceNodes (default true): Resolved names are replaced in-place. Otherwise, a
     *    resolvedName attribute is added. (Names that cannot be statically resolved receive a
     *    namespacedName attribute, as usual.)
     *  * parseCustomAnnotations (default true): Whether to parse DocBlock Custom Annotations.
     *
     * @param ErrorHandler|null                                                                       $errorHandler Error handler
     * @param array{preserveOriginalNames?: bool, replaceNodes?: bool, parseCustomAnnotations?: bool} $options      Options
     *
     * @psalm-suppress TooFewArguments
     * @psalm-suppress InvalidArgument
     */
    public function __construct(?ErrorHandler $errorHandler = null, array $options = [])
    {
        $this->nameContext = new NameContext($errorHandler ?? new ErrorHandler\Throwing());
        $this->preserveOriginalNames = $options['preserveOriginalNames'] ?? false;
        $this->replaceNodes = $options['replaceNodes'] ?? true;
        $this->parseCustomAnnotations = $options['parseCustomAnnotations'] ?? true;

        // this if is to allow using v 1.2 or v2
        if (class_exists(ParserConfig::class)) {
            $parserConfig = new ParserConfig([]);
            $constExprParser = new ConstExprParser($parserConfig);
            $typeParser = new TypeParser($parserConfig, $constExprParser);
            $this->phpDocParser = new PhpDocParser($parserConfig, $typeParser, $constExprParser);
            $this->phpDocLexer = new Lexer($parserConfig);
        } else {
            $typeParser = new TypeParser();
            $constExprParser = new ConstExprParser();
            $this->phpDocParser = new PhpDocParser($typeParser, $constExprParser);
            $this->phpDocLexer = new Lexer();
        }
    }

    /**
     * Get name resolution context.
     */
    public function getNameContext(): NameContext
    {
        return $this->nameContext;
    }

    public function beforeTraverse(array $nodes): ?array
    {
        $this->nameContext->startNamespace();

        return null;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->nameContext->startNamespace($node->name);
        } elseif ($node instanceof Stmt\Use_) {
            foreach ($node->uses as $use) {
                $this->addAlias($use, $node->type, null);
            }
        } elseif ($node instanceof Stmt\GroupUse) {
            foreach ($node->uses as $use) {
                $this->addAlias($use, $node->type, $node->prefix);
            }
        } elseif ($node instanceof Stmt\Class_) {
            if (null !== $node->extends) {
                $node->extends = $this->resolveClassName($node->extends);
            }

            foreach ($node->implements as &$interface) {
                $interface = $this->resolveClassName($interface);
            }

            $this->resolveAttrGroups($node);
            if (null !== $node->name) {
                $this->addNamespacedName($node);
            } else {
                $node->namespacedName = null;
            }
        } elseif ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as &$interface) {
                $interface = $this->resolveClassName($interface);
            }

            $this->resolveAttrGroups($node);
            $this->addNamespacedName($node);
        } elseif ($node instanceof Stmt\Enum_) {
            foreach ($node->implements as &$interface) {
                $interface = $this->resolveClassName($interface);
            }

            $this->resolveAttrGroups($node);
            if (null !== $node->name) {
                $this->addNamespacedName($node);
            }
        } elseif ($node instanceof Stmt\Trait_) {
            $this->resolveAttrGroups($node);
            $this->addNamespacedName($node);
        } elseif ($node instanceof Stmt\Function_) {
            $this->resolveSignature($node);
            $this->resolveAttrGroups($node);
            $this->addNamespacedName($node);
        } elseif (
            $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction
        ) {
            $this->resolveSignature($node);
            $this->resolveAttrGroups($node);
        } elseif ($node instanceof Stmt\Property) {
            if (null !== $node->type) {
                $node->type = $this->resolveType($node->type);
            }
            $this->resolveAttrGroups($node);

            $phpDocNode = $this->getPhpDocNode($node);

            if (null === $phpDocNode) {
                return;
            }

            if ($this->isNodeOfTypeArray($node)) {
                $arrayItemType = null;

                foreach ($phpDocNode->getVarTagValues() as $tagValue) {
                    $arrayItemType = $this->getArrayItemType($tagValue->type);
                }

                if (null !== $arrayItemType) {
                    $node->type = $this->resolveName(new Name($arrayItemType), Stmt\Use_::TYPE_NORMAL);

                    return;
                }
            }

            foreach ($phpDocNode->getVarTagValues() as $tagValue) {
                $type = $this->resolveName(new Name((string) $tagValue->type), Stmt\Use_::TYPE_NORMAL);
                $node->type = $type;
                break;
            }

            if ($this->parseCustomAnnotations && !($node->type instanceof FullyQualified)) {
                foreach ($phpDocNode->getTags() as $tagValue) {
                    if ('@' === $tagValue->name[0] && !str_contains($tagValue->name, '@var')) {
                        $customTag = str_replace('@', '', $tagValue->name);
                        $type = $this->resolveName(new Name($customTag), Stmt\Use_::TYPE_NORMAL);
                        $node->type = $type;

                        break;
                    }
                }
            }
        } elseif ($node instanceof Node\PropertyHook) {
            foreach ($node->params as $param) {
                $param->type = $this->resolveType($param->type);
                $this->resolveAttrGroups($param);
            }
            $this->resolveAttrGroups($node);
        } elseif ($node instanceof Stmt\Const_) {
            foreach ($node->consts as $const) {
                $this->addNamespacedName($const);
            }
        } elseif ($node instanceof Stmt\ClassConst) {
            if (null !== $node->type) {
                $node->type = $this->resolveType($node->type);
            }
            $this->resolveAttrGroups($node);
        } elseif ($node instanceof Stmt\EnumCase) {
            $this->resolveAttrGroups($node);
        } elseif (
            $node instanceof Expr\StaticCall
            || $node instanceof Expr\StaticPropertyFetch
            || $node instanceof Expr\ClassConstFetch
            || $node instanceof Expr\New_
            || $node instanceof Expr\Instanceof_
        ) {
            if ($node->class instanceof Name) {
                $node->class = $this->resolveClassName($node->class);
            }
        } elseif ($node instanceof Stmt\Catch_) {
            foreach ($node->types as &$type) {
                $type = $this->resolveClassName($type);
            }
        } elseif ($node instanceof Expr\FuncCall) {
            if ($node->name instanceof Name) {
                $node->name = $this->resolveName($node->name, Stmt\Use_::TYPE_FUNCTION);
            }
        } elseif ($node instanceof Expr\ConstFetch) {
            $node->name = $this->resolveName($node->name, Stmt\Use_::TYPE_CONSTANT);
        } elseif ($node instanceof Stmt\TraitUse) {
            foreach ($node->traits as &$trait) {
                $trait = $this->resolveClassName($trait);
            }

            foreach ($node->adaptations as $adaptation) {
                if (null !== $adaptation->trait) {
                    $adaptation->trait = $this->resolveClassName($adaptation->trait);
                }

                if ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence) {
                    foreach ($adaptation->insteadof as &$insteadof) {
                        $insteadof = $this->resolveClassName($insteadof);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve name, according to name resolver options.
     *
     * @param Name              $name Function or constant name to resolve
     * @param Stmt\Use_::TYPE_* $type One of Stmt\Use_::TYPE_*
     *
     * @return Name Resolved name, or original name with attribute
     */
    protected function resolveName(Name $name, int $type): Name
    {
        if (!$this->replaceNodes) {
            $resolvedName = $this->nameContext->getResolvedName($name, $type);
            if (null !== $resolvedName) {
                $name->setAttribute('resolvedName', $resolvedName);
            } else {
                $name->setAttribute('namespacedName', FullyQualified::concat(
                    $this->nameContext->getNamespace(),
                    $name,
                    $name->getAttributes()
                ));
            }

            return $name;
        }

        if ($this->preserveOriginalNames) {
            // Save the original name
            $originalName = $name;
            $name = clone $originalName;
            $name->setAttribute('originalName', $originalName);
        }

        $resolvedName = $this->nameContext->getResolvedName($name, $type);
        if (null !== $resolvedName) {
            return $resolvedName;
        }

        // unqualified names inside a namespace cannot be resolved at compile-time
        // add the namespaced version of the name as an attribute
        $name->setAttribute('namespacedName', FullyQualified::concat(
            $this->nameContext->getNamespace(),
            $name,
            $name->getAttributes()
        ));

        return $name;
    }

    protected function resolveClassName(Name $name): Name
    {
        return $this->resolveName($name, Stmt\Use_::TYPE_NORMAL);
    }

    /**
     * @psalm-suppress NoInterfaceProperties
     */
    protected function addNamespacedName(Node $node): void
    {
        $node->namespacedName = Name::concat(
            $this->nameContext->getNamespace(),
            (string) $node->name
        );
    }

    /**
     * @psalm-suppress NoInterfaceProperties
     */
    protected function resolveAttrGroups(Node $node): void
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $attr->name = $this->resolveClassName($attr->name);
            }
        }
    }

    /**
     * @param Stmt\Use_::TYPE_* $type
     *
     * @psalm-suppress PossiblyNullArgument
     */
    private function addAlias(Node\UseItem $use, int $type, ?Name $prefix = null): void
    {
        // Add prefix for group uses
        $name = $prefix ? Name::concat($prefix, $use->name) : $use->name;
        // Type is determined either by individual element or whole use declaration
        $type |= $use->type;

        $this->nameContext->addAlias(
            $name,
            (string) $use->getAlias(),
            $type,
            $use->getAttributes()
        );
    }

    /** @param Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction $node */
    private function resolveSignature($node): void
    {
        $phpDocNode = $this->getPhpDocNode($node);

        foreach ($node->params as $param) {
            $param->type = $this->resolveType($param->type);
            $this->resolveAttrGroups($param);

            if ($this->isNodeOfTypeArray($param) && null !== $phpDocNode) {
                foreach ($phpDocNode->getParamTagValues() as $phpDocParam) {
                    if ($param->var instanceof Expr\Variable && \is_string($param->var->name) && $phpDocParam->parameterName === ('$'.$param->var->name)) {
                        $arrayItemType = $this->getArrayItemType($phpDocParam->type);

                        if (null !== $arrayItemType) {
                            $param->type = $this->resolveName(new Name($arrayItemType), Stmt\Use_::TYPE_NORMAL);
                        }
                    }
                }
            }
        }

        $node->returnType = $this->resolveType($node->returnType);

        if ($node->returnType instanceof Node\Identifier && 'array' === $node->returnType->name && null !== $phpDocNode) {
            $arrayItemType = null;

            foreach ($phpDocNode->getReturnTagValues() as $tagValue) {
                $arrayItemType = $this->getArrayItemType($tagValue->type);
            }

            if (null !== $arrayItemType) {
                $node->returnType = $this->resolveName(new Name($arrayItemType), Stmt\Use_::TYPE_NORMAL);
            }
        }
    }

    /**
     * @psalm-suppress MissingParamType
     * @psalm-suppress PossiblyNullArgument
     * @psalm-suppress MissingReturnType
     * @psalm-suppress InvalidReturnStatement
     *
     * @template T of Node\Identifier|Name|Node\ComplexType|null
     *
     * @param T $node
     *
     * @return T
     */
    private function resolveType(?Node $node): ?Node
    {
        if ($node instanceof Name) {
            return $this->resolveClassName($node);
        }
        if ($node instanceof Node\NullableType) {
            $node->type = $this->resolveType($node->type);

            return $node;
        }
        if ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) {
            foreach ($node->types as &$type) {
                $type = $this->resolveType($type);
            }

            return $node;
        }

        return $node;
    }

    private function getPhpDocNode(NodeAbstract $node): ?PhpDocNode
    {
        if (null === $node->getDocComment()) {
            return null;
        }

        /** @var Doc $docComment */
        $docComment = $node->getDocComment();

        $tokens = $this->phpDocLexer->tokenize($docComment->getText());
        $tokenIterator = new TokenIterator($tokens);

        return $this->phpDocParser->parse($tokenIterator);
    }

    /**
     * @param Node\Param|Stmt\Property $node
     */
    private function isNodeOfTypeArray($node): bool
    {
        return null !== $node->type && isset($node->type->name) && 'array' === $node->type->name;
    }

    private function getArrayItemType(TypeNode $typeNode): ?string
    {
        $arrayItemType = null;

        if ($typeNode instanceof GenericTypeNode) {
            if (1 === \count($typeNode->genericTypes)) {
                // this handles list<ClassName>
                $arrayItemType = (string) $typeNode->genericTypes[0];
            } elseif (2 === \count($typeNode->genericTypes)) {
                // this handles array<int, ClassName>
                $arrayItemType = (string) $typeNode->genericTypes[1];
            }
        }

        if ($typeNode instanceof ArrayTypeNode) {
            // this handles ClassName[]
            $arrayItemType = (string) $typeNode->type;
        }

        $validFqcn = '/^[a-zA-Z_\x7f-\xff\\\\][a-zA-Z0-9_\x7f-\xff\\\\]*[a-zA-Z0-9_\x7f-\xff]$/';

        if (null !== $arrayItemType && !(bool) preg_match($validFqcn, $arrayItemType)) {
            return null;
        }

        return $arrayItemType;
    }
}
