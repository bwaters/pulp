<?php
// PuLP : PHP LP Modeler
// Ported from Python PuLP

// Constants for PuLP
define('VERSION', '3.3.0');
define('EPS', 1e-7);

// Variable categories
define('LpContinuous', 'Continuous');
define('LpInteger', 'Integer');
define('LpBinary', 'Binary');
$LpCategories = [
    LpContinuous => 'Continuous',
    LpInteger => 'Integer',
    LpBinary => 'Binary'
];

// Objective sense
define('LpMinimize', 1);
define('LpMaximize', -1);
$LpSenses = [
    LpMaximize => 'Maximize',
    LpMinimize => 'Minimize'
];
$LpSensesMPS = [
    LpMaximize => 'MAX',
    LpMinimize => 'MIN'
];

// Problem status
define('LpStatusNotSolved', 0);
define('LpStatusOptimal', 1);
define('LpStatusInfeasible', -1);
define('LpStatusUnbounded', -2);
define('LpStatusUndefined', -3);
$LpStatus = [
    LpStatusNotSolved => 'Not Solved',
    LpStatusOptimal => 'Optimal',
    LpStatusInfeasible => 'Infeasible',
    LpStatusUnbounded => 'Unbounded',
    LpStatusUndefined => 'Undefined'
];

// Solution status
define('LpSolutionNoSolutionFound', 0);
define('LpSolutionOptimal', 1);
define('LpSolutionIntegerFeasible', 2);
define('LpSolutionInfeasible', -1);
define('LpSolutionUnbounded', -2);
$LpSolution = [
    LpSolutionNoSolutionFound => 'No Solution Found',
    LpSolutionOptimal => 'Optimal Solution Found',
    LpSolutionIntegerFeasible => 'Solution Found',
    LpSolutionInfeasible => 'No Solution Exists',
    LpSolutionUnbounded => 'Solution is Unbounded'
];
$LpStatusToSolution = [
    LpStatusNotSolved => LpSolutionInfeasible,
    LpStatusOptimal => LpSolutionOptimal,
    LpStatusInfeasible => LpSolutionInfeasible,
    LpStatusUnbounded => LpSolutionUnbounded,
    LpStatusUndefined => LpSolutionInfeasible
];

// Constraint sense
define('LpConstraintLE', -1);
define('LpConstraintEQ', 0);
define('LpConstraintGE', 1);
$LpConstraintTypeToMps = [
    LpConstraintLE => 'L',
    LpConstraintEQ => 'E',
    LpConstraintGE => 'G'
];
$LpConstraintSenses = [
    LpConstraintEQ => '=',
    LpConstraintLE => '<=',
    LpConstraintGE => '>='
];

// LP line size
define('LpCplexLPLineSize', 78);

class PulpError extends Exception {
    // Pulp Exception Class
}
?>
