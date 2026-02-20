<?php
/**
 * PHP port of PuLP unit tests (solver-independent tests only).
 * Mirrors pulp/tests/test_pulp.py, test_lpdot.py, test_sparse.py
 */
require_once 'pulp.php';

// -----------------------------------------------------------------------
// Tiny test framework
// -----------------------------------------------------------------------
$PASSED = 0;
$FAILED = 0;
$ERRORS = [];

function ok($condition, $name) {
    global $PASSED, $FAILED, $ERRORS;
    if ($condition) {
        echo "  PASS: $name\n";
        $PASSED++;
    } else {
        echo "  FAIL: $name\n";
        $FAILED++;
        $ERRORS[] = $name;
    }
}

function assertException(callable $fn, $name) {
    global $PASSED, $FAILED, $ERRORS;
    try {
        $fn();
        echo "  FAIL: $name (no exception thrown)\n";
        $FAILED++;
        $ERRORS[] = $name;
    } catch (\Throwable $e) {
        echo "  PASS: $name (got " . get_class($e) . ": " . $e->getMessage() . ")\n";
        $PASSED++;
    }
}

function section($title) { echo "\n=== $title ===\n"; }

// -----------------------------------------------------------------------
// Helper: build an LpAffineExpression from variable=>coeff pairs
// -----------------------------------------------------------------------
function makeExpr(array $pairs, $constant = 0) {
    $e = new LpAffineExpression();
    $e->constant = $constant;
    foreach ($pairs as [$var, $coeff]) $e->addterm($var, $coeff);
    return $e;
}

// -----------------------------------------------------------------------
// 1.  LpVariable construction
// -----------------------------------------------------------------------
section("LpVariable construction");

$x = new LpVariable("x", 0, 4);
ok($x->name === "x",        "name set correctly");
ok($x->lowBound === 0,      "lowBound set");
ok($x->upBound === 4,       "upBound set");
ok($x->cat === LpContinuous,"default cat is Continuous");
ok($x->varValue === null,   "varValue starts null");

$b = new LpVariable("b", null, null, LpBinary);
ok($b->cat === LpInteger,   "Binary becomes Integer");
ok($b->lowBound === 0,      "Binary lowBound = 0");
ok($b->upBound === 1,       "Binary upBound = 1");
ok($b->isBinary(),          "isBinary() returns true");

$free = new LpVariable("free");
ok($free->isFree(),         "No-bound variable is free");

$fixed = new LpVariable("fixed", 3, 3);
ok($fixed->isConstant(),    "lowBound==upBound => isConstant");

// -----------------------------------------------------------------------
// 2.  LpVariable::dicts
// -----------------------------------------------------------------------
section("LpVariable::dicts");

$vars = LpVariable::dicts("x", [1, 2, 3], 0, null, LpContinuous);
ok(count($vars) === 3,            "dicts creates correct count");
ok($vars[1] instanceof LpVariable,"dicts values are LpVariable");
ok($vars[1]->name === "x_1",      "dicts name format");

// -----------------------------------------------------------------------
// 3.  LpAffineExpression arithmetic
// -----------------------------------------------------------------------
section("LpAffineExpression arithmetic");

$x = new LpVariable("x", 0, 4);
$y = new LpVariable("y", -1, 1);
$z = new LpVariable("z", 0);

// __add
$e1 = makeExpr([[$x, 1], [$y, 4], [$z, 9]]);
ok((string)$e1 === "9*z + 4*y + x" || strpos((string)$e1, "x") !== false,
    "expression toString contains x");

// scalar multiply
$e2 = $e1->__mul(2);
$terms2 = $e2->getTerms();
$coeffs = array_column($terms2, 'coeff');
ok(in_array(2.0, $coeffs),  "__mul(2) doubles a coefficient");
ok(in_array(8.0, $coeffs),  "__mul(2) doubles 4 -> 8");

// __neg
$e3 = $e1->__neg();
$terms3 = $e3->getTerms();
foreach ($terms3 as $d) ok($d['coeff'] < 0, "__neg flips sign of " . $d['var']->name);

// __truediv
$e4 = makeExpr([[$x, 4]])->__truediv(2);
$t4 = array_values($e4->getTerms());
ok(abs($t4[0]['coeff'] - 2.0) < 1e-9, "__truediv(2) halves coefficient");

// addInPlace with numeric
$e5 = makeExpr([[$x, 1]]);
$e5->addInPlace(5);
ok(abs($e5->constant - 5.0) < 1e-9, "addInPlace numeric updates constant");

// subInPlace
$e6 = makeExpr([[$x, 1], [$y, 1]]); // x + y
$e6->subInPlace(makeExpr([[$y, 1]]));// - y  => x
$terms6 = $e6->getTerms();
// y coeff should be 0
$yHash = spl_object_hash($y);
ok(!isset($terms6[$yHash]) || abs($terms6[$yHash]['coeff']) < 1e-9,
    "subInPlace removes y from x+y-y");

// lpSum
$e7 = lpSum([$x, $y, $z]);
$terms7 = $e7->getTerms();
ok(count($terms7) === 3, "lpSum of 3 vars has 3 terms");

// lpSum with expressions
$e8 = lpSum([makeExpr([[$x, 1]]), makeExpr([[$y, 2]])]);
$terms8 = $e8->getTerms();
ok(count($terms8) === 2, "lpSum of 2 expressions has 2 terms");

// constant expression
$ec = new LpAffineExpression(null, 7.0);
ok($ec->isNumericalConstant(), "constant-only expr is numerical constant");
ok(abs($ec->constant - 7.0) < 1e-9, "constant stored correctly");

// isAtomic
$ea = new LpAffineExpression($x);
ok($ea->isAtomic(), "single-var coeff-1 expr is atomic");

// -----------------------------------------------------------------------
// 4.  LpConstraint construction & comparison operators
// -----------------------------------------------------------------------
section("LpConstraint construction");

$x = new LpVariable("x", 0, 4);
$y = new LpVariable("y", -1, 1);
$z = new LpVariable("z", 0);

$expr = makeExpr([[$x, 1], [$y, 1]]);
$c1 = $expr->__le(5);
ok($c1 instanceof LpConstraint,    "__le returns LpConstraint");
ok($c1->sense === LpConstraintLE,  "__le sense is LE");
ok(abs($c1->constant - (-5)) < 1e-9, "__le constant is -rhs");

$c2 = $expr->__ge(10);
ok($c2->sense === LpConstraintGE,  "__ge sense is GE");
ok(abs($c2->constant - (-10)) < 1e-9, "__ge constant correct");

$c3 = makeExpr([[$z, 1], [$y, -1]])->__eq(7);
ok($c3->sense === LpConstraintEQ,  "__eq sense is EQ");
ok(abs($c3->constant - (-7)) < 1e-9, "__eq constant correct");

// getLb / getUb
ok($c1->getUb() !== null && abs($c1->getUb() - 5) < 1e-9, "LE constraint getUb = 5");
ok($c1->getLb() === null,          "LE constraint getLb = null");
ok($c2->getLb() !== null && abs($c2->getLb() - 10) < 1e-9, "GE constraint getLb = 10");
ok($c2->getUb() === null,          "GE constraint getUb = null");
ok($c3->getLb() !== null && abs($c3->getLb() - 7) < 1e-9,  "EQ constraint getLb = 7");
ok($c3->getUb() !== null && abs($c3->getUb() - 7) < 1e-9,  "EQ constraint getUb = 7");

// addterm delegation
$con = new LpConstraint(null, LpConstraintLE);
$con->addterm($x, 1);
$con->addterm($y, 1);
$con->constant = -2;
ok(count($con->getTerms()) === 2, "addterm delegates to expr correctly");

// -----------------------------------------------------------------------
// 5.  LpConstraint string output
// -----------------------------------------------------------------------
section("LpConstraint string output");

$x = new LpVariable("x", 0, 4);
$y = new LpVariable("y", -1, 1);
$z = new LpVariable("z", 0);

$c = makeExpr([[$x, 1], [$y, 1]])->__le(5);
$str = (string)$c;
ok(strpos($str, "<=") !== false, "__toString contains <=");
ok(strpos($str, "5") !== false,  "__toString contains RHS 5");

$cplexStr = $c->asCplexLpConstraint("c1");
ok(strpos($cplexStr, "c1:") !== false, "asCplexLpConstraint has name");
ok(strpos($cplexStr, "<=") !== false,  "asCplexLpConstraint has <=");

// -----------------------------------------------------------------------
// 6.  LpProblem construction and addConstraint
// -----------------------------------------------------------------------
section("LpProblem construction");

$prob = new LpProblem("myProblem", LpMinimize);
ok($prob->name === "myProblem",  "problem name set");
ok($prob->sense === LpMinimize,  "sense is Minimize");
ok($prob->objective === null,    "objective starts null");
ok(count($prob->constraints) === 0, "no constraints initially");

$x = new LpVariable("x", 0, 4);
$y = new LpVariable("y", -1, 1);
$z = new LpVariable("z", 0);

$prob->objective = makeExpr([[$x, 1], [$y, 4], [$z, 9]]);
$prob->addConstraint(makeExpr([[$x,1],[$y,1]])->__le(5), "c1");
$prob->addConstraint(makeExpr([[$x,1],[$z,1]])->__ge(10), "c2");
$prob->addConstraint(makeExpr([[$y,-1],[$z,1]])->__eq(7), "c3");

ok(count($prob->constraints) === 3, "3 constraints added");
ok(isset($prob->constraints["c1"]),  "c1 exists");
ok(isset($prob->constraints["c2"]),  "c2 exists");

$vars = $prob->variables();
ok(count($vars) === 3, "variables() returns 3 vars");
ok($vars[0]->name <= $vars[1]->name, "variables() is sorted");

// auto-naming
$prob2 = new LpProblem("p2", LpMinimize);
$prob2->addConstraint(makeExpr([[$x,1]])->__le(5));
$prob2->addConstraint(makeExpr([[$x,1]])->__le(6));
ok(isset($prob2->constraints["_C1"]), "auto-name _C1 assigned");
ok(isset($prob2->constraints["_C2"]), "auto-name _C2 assigned");

// -----------------------------------------------------------------------
// 7.  LpProblem __repr
// -----------------------------------------------------------------------
section("LpProblem __repr");

$repr = $prob->__repr();
ok(strpos($repr, "MINIMIZE") !== false,  "__repr has MINIMIZE");
ok(strpos($repr, "SUBJECT TO") !== false,"__repr has SUBJECT TO");
ok(strpos($repr, "VARIABLES") !== false, "__repr has VARIABLES");
ok(strpos($repr, "c1:") !== false,       "__repr has c1:");

// -----------------------------------------------------------------------
// 8.  LpProblem setObjective with LpVariable
// -----------------------------------------------------------------------
section("setObjective");

$prob3 = new LpProblem("p3", LpMinimize);
$xv = new LpVariable("xv", 0, 4);
$prob3->setObjective($xv);
ok($prob3->objective instanceof LpAffineExpression, "setObjective(LpVariable) wraps in expression");

// -----------------------------------------------------------------------
// 9.  lpDot tests (from test_lpdot.py)
// -----------------------------------------------------------------------
section("lpDot");

$x = new LpVariable("x");
$y = new LpVariable("y");
$z = new LpVariable("z");

// lpDot([x,y,z], [1,2,3])
$d1 = lpDot([$x, $y, $z], [1, 2, 3]);
$t1 = $d1->getTerms();
$coeffByName = [];
foreach ($t1 as $d) $coeffByName[$d['var']->name] = $d['coeff'];
ok(isset($coeffByName['x']) && abs($coeffByName['x'] - 1) < 1e-9, "lpDot x coeff = 1");
ok(isset($coeffByName['y']) && abs($coeffByName['y'] - 2) < 1e-9, "lpDot y coeff = 2");
ok(isset($coeffByName['z']) && abs($coeffByName['z'] - 3) < 1e-9, "lpDot z coeff = 3");

// lpDot([2x, 2y, 2z], [1,2,3])
$ex = makeExpr([[$x, 2]]);
$ey = makeExpr([[$y, 2]]);
$ez = makeExpr([[$z, 2]]);
$d2 = lpDot([$ex, $ey, $ez], [1, 2, 3]);
$t2 = $d2->getTerms();
$cb2 = [];
foreach ($t2 as $d) $cb2[$d['var']->name] = $d['coeff'];
ok(abs($cb2['x'] - 2) < 1e-9, "lpDot 2x*1 = 2");
ok(abs($cb2['y'] - 4) < 1e-9, "lpDot 2y*2 = 4");
ok(abs($cb2['z'] - 6) < 1e-9, "lpDot 2z*3 = 6");

// lpDot([x+y, y+z, z], [1,2,3])  => x*1 + y*(1+2) + z*(2+3) = x+3y+5z
$exy  = makeExpr([[$x,1],[$y,1]]);
$eyz  = makeExpr([[$y,1],[$z,1]]);
$ez2  = makeExpr([[$z,1]]);
$d3   = lpDot([$exy, $eyz, $ez2], [1, 2, 3]);
$t3   = $d3->getTerms();
$cb3 = [];
foreach ($t3 as $d) $cb3[$d['var']->name] = $d['coeff'];
ok(abs($cb3['x'] - 1) < 1e-9, "lpDot x+y,y+z,z * 1,2,3 => x coeff=1");
ok(abs($cb3['y'] - 3) < 1e-9, "lpDot x+y,y+z,z * 1,2,3 => y coeff=3");
ok(abs($cb3['z'] - 5) < 1e-9, "lpDot x+y,y+z,z * 1,2,3 => z coeff=5");

// -----------------------------------------------------------------------
// 10.  test_non_intermediate_var / test_intermediate_var (from test_pulp.py)
// -----------------------------------------------------------------------
section("lpSum constraint constant");

$x0 = new LpVariable("x0", 0);
$x1 = new LpVariable("x1", 0);
$x2 = new LpVariable("x2", 0);

$prob4 = new LpProblem("p4", LpMinimize);
$sumE = lpSum([$x0, $x1, $x2]);
$prob4->addConstraint($sumE->__ge(2));
$prob4->addConstraint($sumE->__le(5));
$constants = array_map(fn($c) => $c->constant, array_values($prob4->constraints));
ok(in_array(-2.0, $constants), "GE 2 => constant = -2");
ok(in_array(-5.0, $constants), "LE 5 => constant = -5");

// -----------------------------------------------------------------------
// 11.  test_variable_0_is_deleted  (from test_pulp.py)
// -----------------------------------------------------------------------
section("Variable coefficient becomes 0");

$x = new LpVariable("x", 0, 4);
$y = new LpVariable("y", -1, 1);
$z = new LpVariable("z", 0);

// c1 = x + y <= 5
$c1 = makeExpr([[$x,1],[$y,1]])->__le(5);
// c2 = c1 + z - z  (add z then subtract z)
$c2 = $c1->copy();
$c2->addInPlace($z);          // add z with coeff 1
$c2->addInPlace($z, -1);      // subtract z, coeff should be 0
$terms = $c2->getTerms();
$zHash = spl_object_hash($z);
$zCoeff = isset($terms[$zHash]) ? $terms[$zHash]['coeff'] : 0;
ok(abs($zCoeff) < 1e-9, "z coefficient is 0 after add+subtract");
ok((string)$c2 !== "", "constraint with zero-coeff var still has string repr");

// -----------------------------------------------------------------------
// 12.  LpConstraint::valid
// -----------------------------------------------------------------------
section("LpConstraint::valid");

$x = new LpVariable("x", 0, 4);
$y = new LpVariable("y", -1, 1);

// x + y <= 5, with x=3, y=1  =>  value = 3+1-5 = -1, valid for LE
$x->varValue = 3; $y->varValue = 1;
$cv = makeExpr([[$x,1],[$y,1]])->__le(5);
ok($cv->valid(0), "x+y<=5 valid when x=3,y=1");

$x->varValue = 4; $y->varValue = 2; // 4+2=6 > 5
ok(!$cv->valid(0), "x+y<=5 invalid when x=4,y=2");

// EQ constraint: -y+z==7, y=-1, z=6 => -(-1)+6-7 = 0
$z = new LpVariable("z", 0); $z->varValue = 6; $y->varValue = -1;
$ceq = makeExpr([[$y,-1],[$z,1]])->__eq(7);
ok($ceq->valid(1e-7), "-y+z==7 valid when y=-1,z=6");

// -----------------------------------------------------------------------
// 13.  LpVariable::value and valueOrDefault
// -----------------------------------------------------------------------
section("LpVariable value methods");

$v = new LpVariable("v", 0, 10);
ok($v->value() === null,   "value() is null before solve");
ok($v->valueOrDefault() === 0, "valueOrDefault() returns lowBound=0");

$v->varValue = 7.5;
ok(abs($v->value() - 7.5) < 1e-9, "value() returns varValue");

$vfree = new LpVariable("vfree"); // no bounds
ok($vfree->valueOrDefault() === 0, "free var valueOrDefault = 0");

// -----------------------------------------------------------------------
// 14.  LpVariable::fixValue / unfixValue
// -----------------------------------------------------------------------
section("LpVariable fix/unfix");

$fv = new LpVariable("fv", 0, 10);
$fv->varValue = 5.0;
$fv->fixValue();
ok($fv->lowBound === 5.0 && $fv->upBound === 5.0, "fixValue sets both bounds to varValue");
ok($fv->isConstant(), "fixed variable isConstant");
$fv->unfixValue();
ok($fv->lowBound === 0 && $fv->upBound === 10, "unfixValue restores original bounds");

// -----------------------------------------------------------------------
// 15.  LpVariable::roundedValue
// -----------------------------------------------------------------------
section("LpVariable roundedValue");

$iv = new LpVariable("iv", 0, 10, LpInteger);
$iv->varValue = 4.9999999;
ok(abs($iv->roundedValue() - 5.0) < 1e-9, "roundedValue rounds 4.9999999 to 5");

$iv->varValue = 4.5;
ok(abs($iv->roundedValue() - 4.5) < 1e-9, "roundedValue does not round 4.5 (too far)");

// -----------------------------------------------------------------------
// 16.  LpAffineExpression::value and valueOrDefault
// -----------------------------------------------------------------------
section("LpAffineExpression value");

$a = new LpVariable("a", 0); $a->varValue = 2.0;
$b2 = new LpVariable("b2", 0); $b2->varValue = 3.0;
$expr = makeExpr([[$a, 4], [$b2, 9]]);  // 4a + 9b = 8+27 = 35
ok(abs($expr->value() - 35.0) < 1e-9, "expression value = 4*2 + 9*3 = 35");

$bnull = new LpVariable("bnull", 0); // varValue = null
$expr2 = makeExpr([[$a, 1], [$bnull, 1]]);
ok($expr2->value() === null, "expression value is null if any var is null");

// -----------------------------------------------------------------------
// 17.  LpProblem::isMIP
// -----------------------------------------------------------------------
section("LpProblem::isMIP");

$pm = new LpProblem("pm", LpMinimize);
$xc = new LpVariable("xc", 0, 4);
$xi = new LpVariable("xi", 0, 4, LpInteger);
$pm->objective = makeExpr([[$xc, 1]]);
ok(!$pm->isMIP(), "no integer var => not MIP");
$pm->objective = makeExpr([[$xi, 1]]);
ok($pm->isMIP(), "integer var in objective => MIP");

// -----------------------------------------------------------------------
// 18.  LpProblem::writeLP
// -----------------------------------------------------------------------
section("LpProblem::writeLP");

$wp = new LpProblem("writeTest", LpMinimize);
$wx = new LpVariable("wx", 0, 4);
$wy = new LpVariable("wy", -1, 1);
$wp->objective = makeExpr([[$wx, 1], [$wy, 4]]);
$wp->addConstraint(makeExpr([[$wx,1],[$wy,1]])->__le(5), "c1");
$tmpfile = sys_get_temp_dir() . "/pulp_test_write.lp";
$wp->writeLP($tmpfile);
ok(file_exists($tmpfile), "writeLP creates file");
$contents = file_get_contents($tmpfile);
ok(strpos($contents, "MINIMIZE") !== false, "written LP has MINIMIZE");
ok(strpos($contents, "c1:") !== false, "written LP has constraint c1");
@unlink($tmpfile);

// -----------------------------------------------------------------------
// 19.  Division test (from test_pulp.py test_divide)
// -----------------------------------------------------------------------
section("Division");

$x = new LpVariable("x", 0, 4);
$y = new LpVariable("y", -1, 1);
// (2x + 2y) / 2.0  => x + y
$e = makeExpr([[$x, 2], [$y, 2]])->__truediv(2.0);
$td = $e->getTerms();
$cbn = [];
foreach ($td as $d) $cbn[$d['var']->name] = $d['coeff'];
ok(abs($cbn['x'] - 1.0) < 1e-9, "(2x)/2 = 1*x");
ok(abs($cbn['y'] - 1.0) < 1e-9, "(2y)/2 = 1*y");

// -----------------------------------------------------------------------
// 20.  LpConstraint asCplexLpAffineExpression
// -----------------------------------------------------------------------
section("LpConstraint asCplexLpAffineExpression");

$x = new LpVariable("x", 0, 4);
$y = new LpVariable("y", -1, 1);
$c = makeExpr([[$x,1],[$y,4]])->__le(5);
$affStr = $c->asCplexLpAffineExpression("obj");
ok(strpos($affStr, "obj:") !== false, "asCplexLpAffineExpression has name");
ok(strpos($affStr, "x") !== false,    "asCplexLpAffineExpression has x");

// -----------------------------------------------------------------------
// 21.  changeRHS
// -----------------------------------------------------------------------
section("changeRHS");

$x = new LpVariable("x", 0, 4);
$c = makeExpr([[$x, 1]])->__le(5);
ok(abs($c->constant - (-5)) < 1e-9, "initial constant is -5");
$c->changeRHS(10);
ok(abs($c->constant - (-10)) < 1e-9, "changeRHS(10) sets constant to -10");

// -----------------------------------------------------------------------
// 22.  Spaces in problem name replaced with underscores
// -----------------------------------------------------------------------
section("Problem name sanitisation");

$pspace = new LpProblem("my problem", LpMinimize);
ok($pspace->name === "my_problem", "spaces replaced with underscores");

// -----------------------------------------------------------------------
// 23.  lpSum of empty / zeros
// -----------------------------------------------------------------------
section("lpSum edge cases");

$eEmpty = lpSum([]);
ok($eEmpty->isNumericalConstant(), "lpSum([]) is numerical constant");
ok(abs($eEmpty->constant) < 1e-9,  "lpSum([]) constant is 0");

$x = new LpVariable("x", 0, 4);
$eZero = lpSum([makeExpr([[$x, 0]]), makeExpr([[$x, 0]])]);
$tz = $eZero->getTerms();
$xHash = spl_object_hash($x);
ok(!isset($tz[$xHash]) || abs($tz[$xHash]['coeff']) < 1e-9,
    "lpSum of zero-coeff terms => x coeff=0");

// -----------------------------------------------------------------------
// 24.  LpVariable::asCplexLpVariable formatting
// -----------------------------------------------------------------------
section("asCplexLpVariable");

$xf  = new LpVariable("xf", 0, 4);
$xfr = new LpVariable("xfr");          // free
$xlo = new LpVariable("xlo", -5, null);// lower only
$xcx = new LpVariable("xcx", 3, 3);    // constant

ok(strpos($xf->asCplexLpVariable(), "xf") !== false,    "continuous non-zero lb");
ok(strpos($xfr->asCplexLpVariable(), "free") !== false,  "free var shows 'free'");
ok(strpos($xlo->asCplexLpVariable(), "-5") !== false,    "lower bound shown");
ok(strpos($xcx->asCplexLpVariable(), "=") !== false,     "constant var shows '='");

// -----------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------
echo "\n";
echo "========================================\n";
echo "Results: $PASSED passed";
if ($FAILED > 0) {
    echo ", $FAILED FAILED\n";
    echo "Failed tests:\n";
    foreach ($ERRORS as $e) echo "  - $e\n";
} else {
    echo ", 0 failed\n";
}
echo "========================================\n";

exit($FAILED > 0 ? 1 : 0);
?>

