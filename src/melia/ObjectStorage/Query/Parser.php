<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Ast\Between;
use melia\ObjectStorage\Query\Ast\Binary;
use melia\ObjectStorage\Path\PathSegment;
use melia\ObjectStorage\Query\Ast\Column;
use melia\ObjectStorage\Query\Ast\Delete;
use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\FunctionCall;
use melia\ObjectStorage\Query\Ast\InList;
use melia\ObjectStorage\Query\Ast\Insert;
use melia\ObjectStorage\Query\Ast\IsNull;
use melia\ObjectStorage\Query\Ast\Join;
use melia\ObjectStorage\Query\Ast\Like;
use melia\ObjectStorage\Query\Ast\Literal;
use melia\ObjectStorage\Query\Ast\OrderItem;
use melia\ObjectStorage\Query\Ast\Parameter;
use melia\ObjectStorage\Query\Ast\Select;
use melia\ObjectStorage\Query\Ast\SelectItem;
use melia\ObjectStorage\Query\Ast\Source;
use melia\ObjectStorage\Query\Ast\Unary;
use melia\ObjectStorage\Query\Ast\Update;
use melia\ObjectStorage\Query\Exception\QueryException;

/**
 * Parses a query into a syntax tree. The precedence of the operators, from the lowest: OR, AND, NOT,
 * equality (= != IS IN LIKE BETWEEN), comparison (< <= > >=), + -, * /, ||, unary - and +; as in SQLite.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class Parser
{
    /** @var list<Token> */
    private array $tokens = [];
    private int $at = 0;
    private string $query = '';

    /**
     * @throws QueryException
     */
    public static function parse(string $query): Select|Insert|Update|Delete
    {
        $parser = new self();
        $parser->query = $query;
        $parser->tokens = Lexer::tokenize($query);
        return $parser->statement();
    }

    private function statement(): Select|Insert|Update|Delete
    {
        $token = $this->peek();
        $statement = match (true) {
            $token->is(Lexer::KEYWORD, 'INSERT') => $this->insert(),
            $token->is(Lexer::KEYWORD, 'UPDATE') => $this->update(),
            $token->is(Lexer::KEYWORD, 'DELETE') => $this->delete(),
            default => $this->select(),
        };
        $this->accept(Lexer::SYMBOL, ';');
        if (!$this->peek()->is(Lexer::END)) {
            throw $this->error(sprintf('Unexpected "%s" after the end of the query', $this->peek()->value), $this->peek());
        }
        return $statement;
    }

    private function insert(): Insert
    {
        $this->expect(Lexer::KEYWORD, 'INSERT');
        $this->expect(Lexer::KEYWORD, 'INTO');
        $into = $this->source();
        $this->expect(Lexer::SYMBOL, '(');
        $properties = [];
        do {
            $properties[] = $this->property();
        } while ($this->accept(Lexer::SYMBOL, ','));
        $this->expect(Lexer::SYMBOL, ')');
        $this->expect(Lexer::KEYWORD, 'VALUES');
        $rows = [];
        do {
            $start = $this->expect(Lexer::SYMBOL, '(');
            $values = [];
            do {
                $values[] = $this->expression();
            } while ($this->accept(Lexer::SYMBOL, ','));
            $this->expect(Lexer::SYMBOL, ')');
            if (count($values) !== count($properties)) {
                throw $this->error(sprintf('%d values for %d properties', count($values), count($properties)), $start);
            }
            $rows[] = $values;
        } while ($this->accept(Lexer::SYMBOL, ','));
        return new Insert($into, $properties, $rows);
    }

    private function update(): Update
    {
        $this->expect(Lexer::KEYWORD, 'UPDATE');
        $source = $this->source();
        $this->expect(Lexer::KEYWORD, 'SET');
        $assignments = [];
        do {
            $property = $this->property();
            $this->expect(Lexer::SYMBOL, '=');
            $assignments[] = [$property, $this->expression()];
        } while ($this->accept(Lexer::SYMBOL, ','));
        $where = $this->accept(Lexer::KEYWORD, 'WHERE') ? $this->expression() : null;
        return new Update($source, $assignments, $where);
    }

    private function delete(): Delete
    {
        $this->expect(Lexer::KEYWORD, 'DELETE');
        $this->expect(Lexer::KEYWORD, 'FROM');
        $source = $this->source();
        $where = $this->accept(Lexer::KEYWORD, 'WHERE') ? $this->expression() : null;
        return new Delete($source, $where);
    }

    /** a property to write: a name of the object itself, no path */
    private function property(): string
    {
        $name = $this->expect(Lexer::IDENTIFIER);
        if ($this->peek()->is(Lexer::SYMBOL, '.')) {
            throw $this->error('Only properties of the object itself can be written, not paths', $this->peek());
        }
        return $name->value;
    }

    private function select(): Select
    {
        $this->expect(Lexer::KEYWORD, 'SELECT');
        $distinct = null !== $this->accept(Lexer::KEYWORD, 'DISTINCT');

        $items = [];
        do {
            $items[] = $this->selectItem();
        } while ($this->accept(Lexer::SYMBOL, ','));

        $this->expect(Lexer::KEYWORD, 'FROM');
        $from = $this->source();

        $joins = [];
        while (true) {
            if ($this->accept(Lexer::KEYWORD, 'LEFT')) {
                $this->accept(Lexer::KEYWORD, 'OUTER');
                $this->expect(Lexer::KEYWORD, 'JOIN');
                $type = Join::LEFT;
            } elseif ($this->accept(Lexer::KEYWORD, 'INNER')) {
                $this->expect(Lexer::KEYWORD, 'JOIN');
                $type = Join::INNER;
            } elseif ($this->accept(Lexer::KEYWORD, 'JOIN')) {
                $type = Join::INNER;
            } else {
                break;
            }
            $source = $this->source();
            $this->expect(Lexer::KEYWORD, 'ON');
            $joins[] = new Join($type, $source, $this->expression());
        }

        $where = $this->accept(Lexer::KEYWORD, 'WHERE') ? $this->expression() : null;

        $groupBy = [];
        if ($this->accept(Lexer::KEYWORD, 'GROUP')) {
            $this->expect(Lexer::KEYWORD, 'BY');
            do {
                $groupBy[] = $this->expression();
            } while ($this->accept(Lexer::SYMBOL, ','));
        }

        $having = $this->accept(Lexer::KEYWORD, 'HAVING') ? $this->expression() : null;

        $orderBy = [];
        if ($this->accept(Lexer::KEYWORD, 'ORDER')) {
            $this->expect(Lexer::KEYWORD, 'BY');
            do {
                $expression = $this->expression();
                $descending = null !== $this->accept(Lexer::KEYWORD, 'DESC');
                if (!$descending) {
                    $this->accept(Lexer::KEYWORD, 'ASC');
                }
                $orderBy[] = new OrderItem($expression, $descending);
            } while ($this->accept(Lexer::SYMBOL, ','));
        }

        $limit = $offset = null;
        if ($this->accept(Lexer::KEYWORD, 'LIMIT')) {
            $limit = $this->expression();
        }
        if ($this->accept(Lexer::KEYWORD, 'OFFSET')) {
            $offset = $this->expression();
        }

        return new Select($distinct, $items, $from, $joins, $where, $groupBy, $having, $orderBy, $limit, $offset);
    }

    private function selectItem(): SelectItem
    {
        if ($this->accept(Lexer::SYMBOL, '*')) {
            return new SelectItem(null, null);
        }
        // alias.*
        if ($this->peek()->is(Lexer::IDENTIFIER) && $this->peek(1)->is(Lexer::SYMBOL, '.') && $this->peek(2)->is(Lexer::SYMBOL, '*')
            && !$this->peek(3)->is(Lexer::SYMBOL, '.') && !$this->peek(3)->is(Lexer::SYMBOL, '[')) {
            $alias = $this->next()->value;
            $this->next();
            $this->next();
            return new SelectItem(null, null, $alias, $alias . '.*');
        }

        $start = $this->peek()->position;
        $expression = $this->expression();
        $text = trim(substr($this->query, $start, $this->peek()->position - $start));

        $alias = null;
        if ($this->accept(Lexer::KEYWORD, 'AS')) {
            $alias = $this->expect(Lexer::IDENTIFIER)->value;
        } elseif ($this->peek()->is(Lexer::IDENTIFIER)) {
            $alias = $this->next()->value;
        }
        return new SelectItem($expression, $alias, null, $text);
    }

    private function source(): Source
    {
        $class = $this->expect(Lexer::IDENTIFIER);
        $alias = null;
        if ($this->accept(Lexer::KEYWORD, 'AS')) {
            $alias = $this->expect(Lexer::IDENTIFIER)->value;
        } elseif ($this->peek()->is(Lexer::IDENTIFIER)) {
            $alias = $this->next()->value;
        }
        // without an alias: the short name of the class
        $alias ??= substr(strrchr('\\' . $class->value, '\\'), 1);
        return new Source(ltrim($class->value, '\\'), $alias, $class->position);
    }

    // ---- expressions, from the lowest precedence ----

    public function expression(): Expr
    {
        return $this->or();
    }

    private function or(): Expr
    {
        $left = $this->and();
        while ($this->accept(Lexer::KEYWORD, 'OR')) {
            $left = new Binary('OR', $left, $this->and());
        }
        return $left;
    }

    private function and(): Expr
    {
        $left = $this->not();
        while ($this->accept(Lexer::KEYWORD, 'AND')) {
            $left = new Binary('AND', $left, $this->not());
        }
        return $left;
    }

    private function not(): Expr
    {
        if ($this->accept(Lexer::KEYWORD, 'NOT')) {
            return new Unary('NOT', $this->not());
        }
        return $this->equality();
    }

    private function equality(): Expr
    {
        $left = $this->comparison();
        while (true) {
            if ($this->peek()->is(Lexer::SYMBOL, '=') || $this->peek()->is(Lexer::SYMBOL, '!=')) {
                $operator = $this->next()->value;
                $left = new Binary($operator, $left, $this->comparison());
                continue;
            }
            if ($this->accept(Lexer::KEYWORD, 'IS')) {
                $negated = null !== $this->accept(Lexer::KEYWORD, 'NOT');
                $this->expect(Lexer::KEYWORD, 'NULL');
                $left = new IsNull($left, $negated);
                continue;
            }
            // [NOT] IN, BETWEEN, LIKE
            $negated = false;
            if ($this->peek()->is(Lexer::KEYWORD, 'NOT') && ($this->peek(1)->is(Lexer::KEYWORD, 'IN') || $this->peek(1)->is(Lexer::KEYWORD, 'BETWEEN') || $this->peek(1)->is(Lexer::KEYWORD, 'LIKE'))) {
                $this->next();
                $negated = true;
            }
            if ($this->accept(Lexer::KEYWORD, 'IN')) {
                $this->expect(Lexer::SYMBOL, '(');
                $values = [];
                do {
                    $values[] = $this->expression();
                } while ($this->accept(Lexer::SYMBOL, ','));
                $this->expect(Lexer::SYMBOL, ')');
                $left = new InList($left, $values, $negated);
                continue;
            }
            if ($this->accept(Lexer::KEYWORD, 'BETWEEN')) {
                // the AND belongs to BETWEEN: the bounds are of a higher precedence
                $low = $this->comparison();
                $this->expect(Lexer::KEYWORD, 'AND');
                $left = new Between($left, $low, $this->comparison(), $negated);
                continue;
            }
            if ($this->accept(Lexer::KEYWORD, 'LIKE')) {
                $left = new Like($left, $this->comparison(), $negated);
                continue;
            }
            return $left;
        }
    }

    private function comparison(): Expr
    {
        $left = $this->additive();
        while (in_array($this->peek()->value, ['<', '<=', '>', '>='], true) && $this->peek()->is(Lexer::SYMBOL)) {
            $operator = $this->next()->value;
            $left = new Binary($operator, $left, $this->additive());
        }
        return $left;
    }

    private function additive(): Expr
    {
        $left = $this->multiplicative();
        while (($this->peek()->is(Lexer::SYMBOL, '+') || $this->peek()->is(Lexer::SYMBOL, '-'))) {
            $operator = $this->next()->value;
            $left = new Binary($operator, $left, $this->multiplicative());
        }
        return $left;
    }

    private function multiplicative(): Expr
    {
        $left = $this->concatenation();
        while (($this->peek()->is(Lexer::SYMBOL, '*') || $this->peek()->is(Lexer::SYMBOL, '/'))) {
            $operator = $this->next()->value;
            $left = new Binary($operator, $left, $this->concatenation());
        }
        return $left;
    }

    private function concatenation(): Expr
    {
        $left = $this->unary();
        while ($this->accept(Lexer::SYMBOL, '||')) {
            $left = new Binary('||', $left, $this->unary());
        }
        return $left;
    }

    private function unary(): Expr
    {
        if ($this->accept(Lexer::SYMBOL, '-')) {
            $operand = $this->unary();
            // a negative number is a number
            if ($operand instanceof Literal && (is_int($operand->value) || is_float($operand->value))) {
                return new Literal(-$operand->value);
            }
            return new Unary('-', $operand);
        }
        if ($this->accept(Lexer::SYMBOL, '+')) {
            return $this->unary();
        }
        return $this->primary();
    }

    private function primary(): Expr
    {
        $token = $this->next();
        switch ($token->type) {
            case Lexer::NUMBER:
                // digits only: an integer, if it fits
                return new Literal(ctype_digit($token->value) && (float)$token->value <= PHP_INT_MAX
                    ? (int)$token->value
                    : (float)$token->value);
            case Lexer::STRING:
                return new Literal($token->value);
            case Lexer::PARAMETER:
                return new Parameter($token->value, $token->position);
            case Lexer::KEYWORD:
                return match ($token->value) {
                    'TRUE' => new Literal(true),
                    'FALSE' => new Literal(false),
                    'NULL' => new Literal(null),
                    // the UUID of the object of the only source
                    'UUID' => new Column([], $token->position, true),
                    default => throw $this->error(sprintf('Unexpected %s', $token->value), $token),
                };
            case Lexer::SYMBOL:
                if ('(' === $token->value) {
                    $expression = $this->expression();
                    $this->expect(Lexer::SYMBOL, ')');
                    return $expression;
                }
                throw $this->error(sprintf('Unexpected "%s"', $token->value), $token);
            case Lexer::IDENTIFIER:
                if ($this->peek()->is(Lexer::SYMBOL, '(')) {
                    return $this->functionCall($token);
                }
                $segments = [$token->value];
                while (true) {
                    if ($this->peek()->is(Lexer::SYMBOL, '[')) {
                        $segments[] = $this->bracket();
                        continue;
                    }
                    if (!$this->accept(Lexer::SYMBOL, '.')) {
                        break;
                    }
                    // p.UUID, p.category.UUID: the UUID of the object there, at the end of a path
                    if ($this->accept(Lexer::KEYWORD, 'UUID')) {
                        return new Column($segments, $token->position, true);
                    }
                    // p.friends.*.name: all elements
                    if ($this->accept(Lexer::SYMBOL, '*')) {
                        $segments[] = PathSegment::wildcard();
                        continue;
                    }
                    $segments[] = $this->expect(Lexer::IDENTIFIER)->value;
                }
                return new Column($segments, $token->position);
            default:
                throw $this->error('Unexpected end of the query', $token);
        }
    }

    /**
     * A segment in brackets: [0], [-1] (the element at a position), ['key'] (exactly that key), [*] (all elements).
     */
    private function bracket(): PathSegment
    {
        $open = $this->expect(Lexer::SYMBOL, '[');
        $token = $this->next();
        if ($token->is(Lexer::SYMBOL, '*')) {
            $segment = PathSegment::wildcard();
        } elseif ($token->is(Lexer::STRING)) {
            $segment = PathSegment::key($token->value);
        } else {
            $negative = $token->is(Lexer::SYMBOL, '-');
            if ($negative) {
                $token = $this->next();
            }
            if (!$token->is(Lexer::NUMBER) || !ctype_digit($token->value)) {
                throw $this->error("A position (e.g. [0]), a key (e.g. ['alex']) or * expected in brackets", $open);
            }
            $segment = PathSegment::index($negative ? -(int)$token->value : (int)$token->value);
        }
        $this->expect(Lexer::SYMBOL, ']');
        return $segment;
    }

    private function functionCall(Token $name): FunctionCall
    {
        $this->expect(Lexer::SYMBOL, '(');
        $function = strtoupper($name->value);
        if ($this->accept(Lexer::SYMBOL, '*')) {
            if ('COUNT' !== $function) {
                throw $this->error(sprintf('%s(*) isn\'t possible, only COUNT(*)', $function), $name);
            }
            $this->expect(Lexer::SYMBOL, ')');
            return new FunctionCall($function, [], false, true, $name->position);
        }
        $distinct = null !== $this->accept(Lexer::KEYWORD, 'DISTINCT');
        $arguments = [];
        if (!$this->peek()->is(Lexer::SYMBOL, ')')) {
            do {
                $arguments[] = $this->expression();
            } while ($this->accept(Lexer::SYMBOL, ','));
        }
        $this->expect(Lexer::SYMBOL, ')');
        return new FunctionCall($function, $arguments, $distinct, false, $name->position);
    }

    // ---- tokens ----

    private function peek(int $ahead = 0): Token
    {
        return $this->tokens[min($this->at + $ahead, count($this->tokens) - 1)];
    }

    private function next(): Token
    {
        $token = $this->peek();
        if (!$token->is(Lexer::END)) {
            $this->at++;
        }
        return $token;
    }

    private function accept(string $type, ?string $value = null): ?Token
    {
        return $this->peek()->is($type, $value) ? $this->next() : null;
    }

    private function expect(string $type, ?string $value = null): Token
    {
        $token = $this->peek();
        if (!$token->is($type, $value)) {
            $expected = $value ?? match ($type) {
                Lexer::IDENTIFIER => 'a name',
                default => $type,
            };
            $found = $token->is(Lexer::END) ? 'the end of the query' : sprintf('"%s"', $token->value);
            throw $this->error(sprintf('Expected %s, found %s', $expected, $found), $token);
        }
        return $this->next();
    }

    private function error(string $message, Token $token): QueryException
    {
        return QueryException::at($message, $this->query, $token->position);
    }
}
