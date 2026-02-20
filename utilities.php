<?php
// Utility functions for PuLP PHP

function isNumber($x) {
    return is_int($x) || is_float($x);
}

function value($x) {
    if (isNumber($x)) {
        return $x;
    } else {
        return $x->value();
    }
}

function valueOrDefault($x) {
    if (isNumber($x)) {
        return $x;
    } else {
        return $x->valueOrDefault();
    }
}

// Other utility functions can be added as needed
?>
