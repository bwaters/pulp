<?php
require_once 'constants.php';
require_once 'utilities.php';

// LpCplexLPLineSize is defined in constants.php

// -----------------------------------------------------------------------
// LpElement
// -----------------------------------------------------------------------
class LpElement {
    public $name;
    public $hash;
    public $modified = true;

    public function __construct($name) {
        $this->name = $name;
        $this->hash = spl_object_hash($this);
    }

    public function __toString() { return (string)$this->name; }
}

// -----------------------------------------------------------------------
// LpVariable
// -----------------------------------------------------------------------
class LpVariable extends LpElement {
    public $varValue = null;
    public $dj = null;
    public $lowBound;
    public $upBound;
    public $cat;
    private $_lowbound_original;
    private $_upbound_original;

    public function __construct($name, $lowBound = null, $upBound = null, $cat = LpContinuous) {
        parent::__construct($name);
        $this->_lowbound_original = $this->lowBound = $lowBound;
        $this->_upbound_original  = $this->upBound  = $upBound;
        $this->cat = $cat;
        if ($cat == LpBinary) {
            $this->_lowbound_original = $this->lowBound = 0;
            $this->_upbound_original  = $this->upBound  = 1;
            $this->cat = LpInteger;
        }
    }

    public function value()          { return $this->varValue; }
    public function isInteger()      { return $this->cat == LpInteger; }
    public function isBinary()       { return $this->cat == LpInteger && $this->lowBound == 0 && $this->upBound == 1; }
    public function isFree()         { return $this->lowBound === null && $this->upBound === null; }
    public function isConstant()     { return $this->lowBound !== null && $this->upBound == $this->lowBound; }

    public function valueOrDefault() {
        if ($this->varValue !== null) return $this->varValue;
        if ($this->lowBound !== null) {
            if ($this->upBound !== null) {
                if (0 >= $this->lowBound && 0 <= $this->upBound) return 0;
                return ($this->lowBound >= 0) ? $this->lowBound : $this->upBound;
            }
            return (0 >= $this->lowBound) ? 0 : $this->lowBound;
        }
        if ($this->upBound !== null) return (0 <= $this->upBound) ? 0 : $this->upBound;
        return 0;
    }

    public function bounds($low, $up) {
        $this->lowBound = $low; $this->upBound = $up; $this->modified = true;
    }
    public function fixValue()   { if ($this->varValue !== null) $this->bounds($this->varValue, $this->varValue); }
    public function unfixValue() { $this->bounds($this->_lowbound_original, $this->_upbound_original); }

    public function valid($eps) {
        if ($this->name == "__dummy" && $this->varValue === null) return true;
        if ($this->varValue === null) return false;
        if ($this->upBound  !== null && $this->varValue > $this->upBound  + $eps) return false;
        if ($this->lowBound !== null && $this->varValue < $this->lowBound - $eps) return false;
        if ($this->cat == LpInteger && abs(round($this->varValue) - $this->varValue) > $eps) return false;
        return true;
    }

    public function roundedValue($eps = 1e-5) {
        if ($this->cat == LpInteger && $this->varValue !== null
            && abs($this->varValue - round($this->varValue)) <= $eps)
            return (float)round($this->varValue);
        return $this->varValue;
    }

    public function asCplexLpVariable() {
        if ($this->isFree())     return $this->name . " free";
        if ($this->isConstant()) return $this->name . " = " . $this->lowBound;
        $s = ($this->lowBound === null)  ? "-inf <= "
           : ($this->lowBound == 0 && $this->cat == LpContinuous ? "" : $this->lowBound . " <= ");
        $s .= $this->name;
        if ($this->upBound !== null) $s .= " <= " . $this->upBound;
        return $s;
    }

    public static function dicts($name, $indices, $lowBound = null, $upBound = null, $cat = LpContinuous) {
        $d = [];
        foreach ($indices as $i) $d[$i] = new LpVariable("{$name}_{$i}", $lowBound, $upBound, $cat);
        return $d;
    }
}

// -----------------------------------------------------------------------
// LpAffineExpression
// -----------------------------------------------------------------------
class LpAffineExpression {
    public  $constant = 0.0;
    public  $name = null;
    // hash => ['var' => LpVariable, 'coeff' => float]
    protected $terms = [];

    public function __construct($e = null, $constant = 0.0, $name = null) {
        $this->name     = $name;
        $this->constant = (float)$constant;
        if ($e === null) return;
        if ($e instanceof LpAffineExpression) {
            $this->constant = $e->constant;
            foreach ($e->terms as $h => $d) $this->terms[$h] = $d;
        } elseif ($e instanceof LpVariable) {
            $this->constant = 0.0;
            $this->addterm($e, 1.0);
        } elseif (is_numeric($e)) {
            $this->constant = (float)$e;
        } elseif (is_array($e)) {
            foreach ($e as $pair) $this->addterm($pair[0], $pair[1]);
        }
    }

    public function addterm($var, $coeff) {
        $h = spl_object_hash($var);
        if (isset($this->terms[$h])) $this->terms[$h]['coeff'] += (float)$coeff;
        else $this->terms[$h] = ['var' => $var, 'coeff' => (float)$coeff];
    }

    public function getTerms()           { return $this->terms; }
    public function isNumericalConstant(){ return count($this->terms) === 0; }
    public function isAtomic()           { return count($this->terms) === 1 && $this->constant == 0 && abs(current($this->terms)['coeff'] - 1) < 1e-10; }
    public function atom()               { return current($this->terms)['var']; }

    public function copy() {
        $e = new LpAffineExpression();
        $e->constant = $this->constant;
        $e->name     = $this->name;
        foreach ($this->terms as $h => $d) $e->terms[$h] = $d;
        return $e;
    }

    public function value() {
        $s = $this->constant;
        foreach ($this->terms as $d) {
            if ($d['var']->varValue === null) return null;
            $s += $d['var']->varValue * $d['coeff'];
        }
        return $s;
    }

    public function valueOrDefault() {
        $s = $this->constant;
        foreach ($this->terms as $d) $s += $d['var']->valueOrDefault() * $d['coeff'];
        return $s;
    }

    // ---- arithmetic ----
    public function addInPlace($other, $sign = 1) {
        if ($other === null)       return $this;
        if (is_numeric($other))    { $this->constant += $other * $sign; return $this; }
        if ($other instanceof LpVariable) { $this->addterm($other, $sign); return $this; }
        if ($other instanceof LpAffineExpression) {
            $this->constant += $other->constant * $sign;
            foreach ($other->terms as $d) $this->addterm($d['var'], $d['coeff'] * $sign);
            return $this;
        }
        return $this;
    }

    public function subInPlace($other) { return $this->addInPlace($other, -1); }

    public function __add($other) { return $this->copy()->addInPlace($other); }
    public function __sub($other) { return $this->copy()->subInPlace($other); }
    public function __neg()       { return $this->__mul(-1); }

    public function __mul($other) {
        if (!is_numeric($other)) throw new \TypeError("Cannot multiply LpAffineExpression by non-number");
        $e = new LpAffineExpression();
        $e->constant = $this->constant * $other;
        foreach ($this->terms as $h => $d)
            $e->terms[$h] = ['var' => $d['var'], 'coeff' => $d['coeff'] * $other];
        return $e;
    }

    public function __truediv($other) {
        if (!is_numeric($other)) throw new \TypeError("Cannot divide LpAffineExpression by non-number");
        return $this->__mul(1.0 / $other);
    }

    // ---- comparison → LpConstraint ----
    public function __le($other) {
        if (is_numeric($other)) return new LpConstraint($this, LpConstraintLE, null, $other);
        $diff = $this->copy()->subInPlace($other instanceof LpVariable ? new LpAffineExpression($other) : $other);
        return new LpConstraint($diff, LpConstraintLE);
    }
    public function __ge($other) {
        if (is_numeric($other)) return new LpConstraint($this, LpConstraintGE, null, $other);
        $diff = $this->copy()->subInPlace($other instanceof LpVariable ? new LpAffineExpression($other) : $other);
        return new LpConstraint($diff, LpConstraintGE);
    }
    public function __eq($other) {
        if (is_numeric($other)) return new LpConstraint($this, LpConstraintEQ, null, $other);
        $diff = $this->copy()->subInPlace($other instanceof LpVariable ? new LpAffineExpression($other) : $other);
        return new LpConstraint($diff, LpConstraintEQ);
    }

    // ---- string output ----
    protected function sorted_terms() {
        $arr = array_values($this->terms);
        usort($arr, fn($a, $b) => strcmp($a['var']->name, $b['var']->name));
        return $arr;
    }

    public function toString($include_constant = true, $override_constant = null) {
        $s = "";
        foreach ($this->sorted_terms() as $d) {
            $val = $d['coeff'];
            if ($val < 0) { $s .= ($s != "" ? " - " : "-"); $val = -$val; }
            elseif ($s != "") { $s .= " + "; }
            $coefStr = ($val == (int)$val) ? (string)(int)$val : rtrim(number_format($val, 12, '.', ''), '0');
            $s .= ($val == 1 ? "" : $coefStr . "*") . $d['var']->name;
        }
        if ($include_constant) {
            $c = $override_constant !== null ? $override_constant : $this->constant;
            if ($s == "") return (string)$c;
            if ($c < 0)     $s .= " - " . (-$c);
            elseif ($c > 0) $s .= " + " . $c;
        } elseif ($s == "") {
            $s = "0";
        }
        return $s;
    }

    public function __toString()               { return $this->toString(); }
    public function __repr($override_constant = null) {
        $c = $override_constant !== null ? $override_constant : $this->constant;
        $parts = [];
        foreach ($this->sorted_terms() as $d) $parts[] = $d['coeff'] . "*" . $d['var']->name;
        $parts[] = (string)$c;
        return implode(" + ", $parts);
    }

    public function asCplexVariablesOnly($name) {
        $result = []; $line = [$name . ":"]; $notFirst = false;
        foreach ($this->sorted_terms() as $d) {
            $val = $d['coeff'];
            if ($val < 0)      { $sign = " -"; $val = -$val; }
            elseif ($notFirst) { $sign = " +"; }
            else               { $sign = ""; }
            $notFirst = true;
            $term = $val == 1
                ? "{$sign} {$d['var']->name}"
                : "{$sign} " . number_format($val, 12, '.', '') . " {$d['var']->name}";
            if (strlen(implode("", $line)) + strlen($term) > LpCplexLPLineSize) {
                $result[] = implode("", $line); $line = [$term];
            } else { $line[] = $term; }
        }
        return [$result, $line];
    }

    public function asCplexLpAffineExpression($name, $include_constant = true, $override_constant = null) {
        [$result, $line] = $this->asCplexVariablesOnly($name);
        if (count($this->terms) == 0) {
            $line[] = " 0";
        } else {
            $c    = $override_constant !== null ? $override_constant : $this->constant;
            $term = "";
            if ($include_constant) {
                if ($c < 0)     $term = " - " . (-$c);
                elseif ($c > 0) $term = " + " . $c;
            }
            if (strlen(implode("", $line)) + strlen($term) > LpCplexLPLineSize) {
                $result[] = implode("", $line); $line = [$term];
            } else { $line[] = $term; }
        }
        $result[] = implode("", $line);
        return implode("\n", $result) . "\n";
    }
}

// -----------------------------------------------------------------------
// LpConstraint  — ported from pulp.py class LpConstraint
// -----------------------------------------------------------------------
class LpConstraint {
    /** @var LpAffineExpression */
    public $expr;
    public $constant = 0.0;
    public $sense;
    public $name    = null;
    public $pi      = null;
    public $slack   = null;
    public $modified = true;

    public function __construct($e = null, $sense = LpConstraintEQ, $name = null, $rhs = null) {
        if ($e instanceof LpAffineExpression)  $this->expr = $e->copy();
        elseif ($e instanceof LpVariable)      $this->expr = new LpAffineExpression($e);
        elseif ($e === null)                   $this->expr = new LpAffineExpression();
        else                                   $this->expr = new LpAffineExpression($e);

        $this->constant = (float)$this->expr->constant;
        $this->name     = $name;
        $this->sense    = $sense;
        if ($rhs !== null) $this->constant -= (float)$rhs;
    }

    public function getLb() {
        return ($this->sense == LpConstraintGE || $this->sense == LpConstraintEQ) ? -$this->constant : null;
    }
    public function getUb() {
        return ($this->sense == LpConstraintLE || $this->sense == LpConstraintEQ) ? -$this->constant : null;
    }

    // Delegate addterm so test.php can call $con->addterm($x, 1)
    public function addterm($var, $coeff) { $this->expr->addterm($var, $coeff); }
    public function getTerms()            { return $this->expr->getTerms(); }

    public function copy() {
        return new LpConstraint(
            $this->expr->copy(), $this->sense, $this->name,
            -$this->constant + $this->expr->constant
        );
    }

    public function addInPlace($other, $sign = 1) {
        if ($other instanceof LpConstraint) {
            if (!($this->sense * $other->sense >= 0)) $sign = -$sign;
            $this->constant += $other->constant * $sign;
            $this->expr->addInPlace($other->expr, $sign);
            $this->sense |= $other->sense * $sign;
        } elseif (is_numeric($other)) {
            $this->constant += $other * $sign;
        } elseif ($other instanceof LpAffineExpression) {
            $this->constant += $other->constant * $sign;
            $this->expr->addInPlace($other, $sign);
        } elseif ($other instanceof LpVariable) {
            $this->expr->addInPlace($other, $sign);
        }
        return $this;
    }

    public function subInPlace($other) { return $this->addInPlace($other, -1); }

    public function changeRHS($rhs) { $this->constant = -(float)$rhs; $this->modified = true; }

    public function value() {
        $s = $this->constant;
        foreach ($this->expr->getTerms() as $d) {
            if ($d['var']->varValue === null) return null;
            $s += $d['var']->varValue * $d['coeff'];
        }
        return $s;
    }

    public function valid($eps = 0) {
        $val = $this->value();
        if ($val === null) return false;
        if ($this->sense == LpConstraintEQ) return abs($val) <= $eps;
        return $val * $this->sense >= -$eps;
    }

    public function __toString() {
        $s = $this->expr->toString(false, $this->constant);
        if ($this->sense !== null)
            $s .= " " . $GLOBALS['LpConstraintSenses'][$this->sense] . " " . (-$this->constant);
        return $s;
    }

    public function __repr() {
        $s = $this->expr->__repr($this->constant);
        if ($this->sense !== null)
            $s .= " " . $GLOBALS['LpConstraintSenses'][$this->sense] . " 0";
        return $s;
    }

    public function asCplexLpConstraint($name) {
        [$result, $line] = $this->expr->asCplexVariablesOnly($name);
        if (count($this->expr->getTerms()) == 0) $line[] = "0";
        $c    = -$this->constant;
        $cStr = ($c == (int)$c) ? (string)(int)$c : rtrim(number_format($c, 12, '.', ''), '0');
        $term = " " . $GLOBALS['LpConstraintSenses'][$this->sense] . " " . $cStr;
        if (strlen(implode("", $line)) + strlen($term) > LpCplexLPLineSize) {
            $result[] = implode("", $line); $line = [$term];
        } else { $line[] = $term; }
        $result[] = implode("", $line);
        return implode("\n", $result) . "\n";
    }

    public function asCplexLpAffineExpression($name, $include_constant = true) {
        return $this->expr->asCplexLpAffineExpression($name, $include_constant, $this->constant);
    }
}

// -----------------------------------------------------------------------
// LpProblem
// -----------------------------------------------------------------------
class LpProblem {
    public $name;
    public $sense;
    public $objective   = null;  // LpAffineExpression
    public $constraints = [];    // name => LpConstraint
    public $status;
    public $sol_status;
    public $solver      = null;
    private $_variables    = [];
    private $_variable_ids = [];
    private $lastUnused    = 0;

    public function __construct($name = "NoName", $sense = LpMinimize) {
        $this->name       = str_replace(" ", "_", $name);
        $this->sense      = $sense;
        $this->status     = LpStatusNotSolved;
        $this->sol_status = LpSolutionNoSolutionFound;
    }

    private function _addVar($var) {
        $h = spl_object_hash($var);
        if (!isset($this->_variable_ids[$h])) {
            $this->_variables[]     = $var;
            $this->_variable_ids[$h] = true;
        }
    }

    public function variables() {
        $this->_variables    = [];
        $this->_variable_ids = [];
        if ($this->objective !== null)
            foreach ($this->objective->getTerms() as $d) $this->_addVar($d['var']);
        foreach ($this->constraints as $c)
            foreach ($c->expr->getTerms() as $d) $this->_addVar($d['var']);
        usort($this->_variables, fn($a, $b) => strcmp($a->name, $b->name));
        return $this->_variables;
    }

    private function unusedConstraintName() {
        $this->lastUnused++;
        while (isset($this->constraints["_C{$this->lastUnused}"])) $this->lastUnused++;
        return "_C{$this->lastUnused}";
    }

    public function addConstraint($constraint, $name = null) {
        if (!($constraint instanceof LpConstraint))
            throw new \TypeError("Can only add LpConstraint objects");
        if ($name !== null) $constraint->name = $name;
        $cname = $constraint->name ?? $this->unusedConstraintName();
        $this->constraints[$cname] = $constraint;
    }

    public function add($constraint, $name = null) { $this->addConstraint($constraint, $name); }

    public function setObjective($expr) {
        if ($expr instanceof LpVariable) $expr = new LpAffineExpression($expr);
        $this->objective = $expr;
    }

    public function isMIP() {
        foreach ($this->variables() as $v) if ($v->isInteger()) return true;
        return false;
    }

    public function __repr() {
        $s  = $this->name . ":\n";
        $s .= ($this->sense == LpMinimize ? "MINIMIZE" : "MAXIMIZE") . "\n";
        $s .= ($this->objective ? (string)$this->objective : "0") . "\n";
        if ($this->constraints) {
            $s .= "SUBJECT TO\n";
            foreach ($this->constraints as $n => $c) $s .= $c->asCplexLpConstraint($n);
        }
        $s .= "VARIABLES\n";
        foreach ($this->variables() as $v)
            $s .= $v->asCplexLpVariable() . " " . $GLOBALS['LpCategories'][$v->cat] . "\n";
        return $s;
    }

    public function solve($solver = null) {
        if (!$solver) $solver = $this->solver ?? new LpSolverDefault();
        $status = $solver->actualSolve($this);
        $this->status = $status;
        return $status;
    }

    public function writeLP($filename) { file_put_contents($filename, $this->__repr()); }
}

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------
function lpSum($vector) {
    $e = new LpAffineExpression();
    foreach ($vector as $item) $e->addInPlace($item);
    return $e;
}

function lpDot($v1, $v2) {
    $v1 = array_values(is_array($v1) ? $v1 : iterator_to_array($v1));
    $v2 = array_values(is_array($v2) ? $v2 : iterator_to_array($v2));
    $e = new LpAffineExpression();
    foreach ($v1 as $k => $a) {
        $b = $v2[$k];
        // Determine the scalar and the expression side
        if (is_numeric($a) && is_numeric($b)) {
            $e->constant += $a * $b;
        } elseif (is_numeric($b)) {
            // $a is LpVariable or LpAffineExpression
            if ($a instanceof LpVariable) {
                $e->addterm($a, $b);
            } elseif ($a instanceof LpAffineExpression) {
                $e->addInPlace($a->__mul($b));
            }
        } elseif (is_numeric($a)) {
            // $b is LpVariable or LpAffineExpression
            if ($b instanceof LpVariable) {
                $e->addterm($b, $a);
            } elseif ($b instanceof LpAffineExpression) {
                $e->addInPlace($b->__mul($a));
            }
        }
    }
    return $e;
}

// -----------------------------------------------------------------------
// Default solver stub — replace with real CBC/GLPK/HiGHS integration
// -----------------------------------------------------------------------
class LpSolverDefault {
    public function actualSolve($lp) {
        foreach ($lp->variables() as $v)
            if ($v->varValue === null) $v->varValue = 0.0;
        return LpStatusOptimal;
    }
}
?>





