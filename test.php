<?php
require_once 'pulp.php';

// Simple test
$x = new LpVariable("x", 0, 3);
$y = new LpVariable("y", null, null, LpBinary);

$prob = new LpProblem("myProblem", LpMinimize);

$prob->objective = new LpAffineExpression();
$prob->objective->addterm($x, -4);
$prob->objective->addterm($y, 1);

$con = new LpConstraint();
$con->addterm($x, 1);
$con->addterm($y, 1);
$con->constant = -2;
$prob->addConstraint($con);


echo "Problem:\n";
echo $prob->__repr();

$status = $prob->solve();
echo "Status: " . $GLOBALS['LpStatus'][$status] . "\n";
echo "x = " . value($x) . "\n";
echo "y = " . value($y) . "\n";
?>
