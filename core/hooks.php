<?php
$actions = $actions ?? [];
$filters = $filters ?? [];
function add_action($hook, $callback) { global $actions; $actions[$hook][] = $callback; }
function do_action($hook, ...$args) { global $actions; if (!empty($actions[$hook])) { foreach ($actions[$hook] as $callback) { call_user_func_array($callback, $args); } } }
function add_filter($hook, $callback) { global $filters; $filters[$hook][] = $callback; }
function apply_filters($hook, $value) { global $filters; $args = func_get_args(); if (!empty($filters[$hook])) { foreach ($filters[$hook] as $callback) { $args[1] = $value; $value = call_user_func_array($callback, array_slice($args,1)); } } return $value; }
