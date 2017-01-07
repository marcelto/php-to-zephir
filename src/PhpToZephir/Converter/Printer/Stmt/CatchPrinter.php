<?php
/**
 * Copyright (c) 2017.
 *
 *
 */

namespace PhpToZephir\Converter\Printer\Stmt;

use PhpParser\Node\Stmt;
use PhpToZephir\Converter\SimplePrinter;

class CatchPrinter extends SimplePrinter
{
    /**
     * @return string
     */
    public static function getType()
    {
        return 'pStmt_Catch';
    }

    /**
     * @param Stmt\Catch_ $node
     *
     * @return string
     */
    public function convert(Stmt\Catch_ $node)
    {
        if (!isset($node->type)) {
            $type = $node->types[0];
        } else {
            $type = $node->type;
        }
        return ' catch '.$this->dispatcher->p($type).', '.$node->var.' {'
             .$this->dispatcher->pStmts($node->stmts)."\n".'}';
    }
}
