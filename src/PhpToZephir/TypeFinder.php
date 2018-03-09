<?php
namespace PhpToZephir;

use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Tag\ParamTag;
use phpDocumentor\Reflection\DocBlock\Tag\ReturnTag;
use phpDocumentor\Reflection\DocBlock\Tag\ThrowsTag;
use phpDocumentor\Reflection\DocBlock\Tag\SeeTag;
use PhpParser\NodeAbstract;
use PhpToZephir\Converter\ClassMetadata;
use PhpParser\Node;

class TypeFinder
{
    /**
     * @var ReservedWordReplacer
     */
    private $reservedWordReplacer = null;
    /**
     * @var Logger
     */
    private $logger = null;
    /**
     * @var ClassCollector
     */
    private $classCollector = null;
    /**
     * @var NodeFetcher
     */
    private $nodeFetcher = null;

    /**
     * @param ReservedWordReplacer $reservedWordReplacer
     * @param Logger               $logger
     * @param ClassCollector       $classCollector
     * @param NodeFetcher          $nodeFetcher
     */
    public function __construct(
        ReservedWordReplacer $reservedWordReplacer,
        Logger $logger,
        ClassCollector $classCollector,
        NodeFetcher $nodeFetcher
    ) {
        $this->reservedWordReplacer = $reservedWordReplacer;
        $this->logger = $logger;
        $this->classCollector = $classCollector;
        $this->nodeFetcher = $nodeFetcher;
    }

    /**
     * @param NodeAbstract  $node
     * @param ClassMetadata $classMetadata
     *
     * @return array
     */
    public function getTypes(NodeAbstract $node, ClassMetadata $classMetadata)
    {
        $definition = [];

        $definition = $this->parseParam($node, $classMetadata, $definition);

        $phpdoc = $this->nodeToDocBlock($node);

        return $this->findReturnTag($phpdoc, $definition, $classMetadata, $node);
    }

    /**
     * @param NodeAbstract $node
     * @param string       $actualNamespace
     * @param array        $use
     * @param array        $classes
     * @param array        $definition
     *
     * @return array
     */
    private function parseParam(NodeAbstract $node, ClassMetadata $classMetadata, array $definition)
    {
        if (isset($definition['params']) === false) {
            $definition['params'] = [];
        }

        foreach ($node->params as $param) {
            $params = [];
            $params['name'] = $this->replaceReservedWords($param->name);
            $params['default'] = $param->default;
            $params['type'] = null;

            /* @var $param \PhpParser\Node\Param */
            if ($param->type === 'array') {
                $params['type']['value'] = 'array';
                $params['type']['isClass'] = false;
            } elseif ($param->type === null) { // scalar or not strong typed in method
                $docBlock = $this->nodeToDocBlock($node);
                if ($docBlock !== null) {
                    $params['type'] = $this->foundTypeInCommentForVar($docBlock, $param, $classMetadata);
                }
            } elseif ($param->type instanceof \PhpParser\Node\Name) {
                $className = /*($param->type->firstSlash ? '\\':'') . */implode('\\', $param->type->parts);
                $params['type']['value'] = $className;
                $params['type']['isClass'] = true;
            }

            $definition['params'][] = $params;
        }

        return $definition;
    }

    /**
     * @param string $string
     */
    private function replaceReservedWords($string)
    {
        return $this->reservedWordReplacer->replace($string);
    }

    /**
     * @param NodeAbstract $node
     *
     * @return NULL|\phpDocumentor\Reflection\DocBlock
     */
    private function nodeToDocBlock(NodeAbstract $node)
    {
        $attribute = $node->getAttributes();

        if (isset($attribute['comments']) === false || isset($attribute['comments'][0]) === false) {
            return;
        }

        $docBlock = $attribute['comments'][0]->getText();

        return new DocBlock($docBlock);
    }

    /**
     * @param DocBlock $phpdoc
     * @param Param    $param
     * @param string   $actualNamespace
     * @param array    $use
     * @param array    $classes
     *
     * @return null|array
     */
    private function foundTypeInCommentForVar(DocBlock $phpdoc, Param $param, ClassMetadata $classMetadata)
    {
        foreach ($phpdoc->getTags() as $tag) {
            if ($tag instanceof \phpDocumentor\Reflection\DocBlock\Tag\ParamTag) {
                if ($param->name === substr($tag->getVariableName(), 1)) {
                    if (!empty($tag->getType())) {
                        return $this->findType($tag, $param, $classMetadata);
                    }
                }
            }
        }

        return;
    }

    /**
     * @param string   $actualNamespace
     * @param array    $definition
     * @param array    $use
     * @param array    $classes
     * @param DocBlock $phpdoc
     *
     * @return array
     */
    private function findReturnTag($phpdoc = null, array $definition, ClassMetadata $classMetadata, NodeAbstract $node)
    {
        $implements = $classMetadata->getImplements();
        if (is_array($implements) === true) {
            foreach ($implements as $implement) {
                foreach ($this->classCollector->getCollected() as $className => $classInfo) {
                    if ($classMetadata->getNamespace() . '\\' . $implement === $className) {
                        try {
                            $phpdoc = $this->nodeToDocBlock($this->findMethod($classInfo, $node->name));
                        } catch (\InvalidArgumentException $e) {
                        }
                    }
                    foreach ($classMetadata->getClasses() as $use) {
                        if ($use . '/' . $implement === $className) {
                            try {
                                $phpdoc = $this->nodeToDocBlock($this->findMethod($classInfo, $node->name));
                            } catch (\InvalidArgumentException $e) {
                            }
                        }
                    }
                }
            }
        }

        if ($phpdoc !== null) {
            foreach ($phpdoc->getTags() as $tag) {
                if ($this->isReturnTag($tag) === true) {
                    $definition['return'] = [
                        'type' => $this->findType($tag, $node, $classMetadata),
                    ];
                    break;
                }
            }
        }

        return $definition;
    }

    /**
     * @param ClassMethod $classInfo
     * @param string      $name
     *
     * @throws \InvalidArgumentException
     *
     * @return \PhpParser\Node\Stmt\ClassMethod
     */
    private function findMethod(array $classInfo, $name)
    {
        foreach ($this->nodeFetcher->foreachNodes($classInfo) as $stmtData) {
            $stmt = $stmtData['node'];
            if ($stmt instanceof ClassMethod && $stmt->name === $name) {
                return $stmt;
            }
        }

        throw new \InvalidArgumentException(sprintf('method %s not found', $name));
    }

    /**
     * @param Tag $tag
     *
     * @return bool
     */
    private function isReturnTag(Tag $tag)
    {
        if ($tag instanceof ReturnTag
            && ($tag instanceof ThrowsTag) === false
            && ($tag instanceof ParamTag) === false
        ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param string $actualNamespace
     * @param Tag    $tag
     *
     * @return string
     */
    private function findType(Tag $tag, Node $node, ClassMetadata $classMetadata)
    {
        $rawType = $tag->getType();

        if ($rawType === 'integer') {
            $rawType = 'int';
        }

        $primitiveTypes = [
            'string',
            'int',
            'integer',
            'float',
            'double',
            'bool',
            'boolean',
            'array',
            'null',
            'callable',
            'scalar',
            'void',
            'object',
        ];

        $excludedType = [
            'mixed',
            'callable',
            'callable[]',
            'scalar',
            'scalar[]',
            'void',
            'object',
            'self',
            'resource',
            'true'
        ];

        if (in_array($rawType, $excludedType) === true || count(explode('|', $rawType)) !== 1) {
            return ['value' => '', 'isClass' => false];
        }

        $arrayOfPrimitiveTypes = array_map(function ($val) {
            return $val . '[]';
        }, $primitiveTypes);

        if (class_exists($rawType)) {
            $type = ['value' => $rawType, 'isClass' => true];
        } elseif ($name = $this->isInUse($rawType, $classMetadata)) {
            $type = ['value' => $name, 'isClass' => true];
        } elseif ($name = $this->isInActualNamespaceOrInBase($rawType)) {
            $type = ['value' => $name, 'isClass' => true];
        } elseif (strpos($rawType, '[]')) {
            $type = ['value' => 'array', 'isClass' => false];
        } elseif (preg_match("/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/", $rawType) === 0) { // this is a typo
            $this->logger->logNode(
                sprintf('Type "%s" does not exist in docblock', $rawType),
                $node,
                $classMetadata->getFullQualifiedNameClass()
            );
            $type = ['value' => '', 'isClass' => false];
        } elseif (in_array(strtolower($rawType), $primitiveTypes)) {
            $type = ['value' => strtolower($rawType), 'isClass' => false];
        } elseif (in_array(strtolower($rawType), $arrayOfPrimitiveTypes)) {
            $type = ['value' => strtolower($rawType), 'isClass' => false];
        } else { // considered as class
            $type = ['value' => $rawType, 'isClass' => true];
        }


        return $type;
    }

    /**
     * @param string        $rawType
     * @param ClassMetadata $classMetadata
     *
     * @return string|boolean
     */
    private function isInUse($rawType, ClassMetadata $classMetadata)
    {
        $rawType = substr($rawType, 1);

        foreach ($classMetadata->getClasses() as $use) {
            if (substr($use, -strlen($rawType)) == $rawType && substr(substr($use, -(strlen($rawType) + 1)), 0,
                    1) === "\\"
            ) {
                return $rawType;
            }
        }

        return false;
    }

    private function isInActualNamespaceOrInBase($rawType)
    {
        $type = substr($rawType, 1);

        foreach (array_keys($this->classCollector->getCollected()) as $class) {
            // is in actual namespace ?
            if (substr($class, -strlen($type)) == $type && substr(substr($class, -(strlen($type) + 1)), 0,
                    1) === "\\"
            ) {
                return $type;
            } elseif ($class === $type) {
                return $rawType;
            }
        }

        return false;
    }
}
