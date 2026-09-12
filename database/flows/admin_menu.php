<?php
/*
 * Owner menu. Same shape as the crew one: one gate at the top, a ladder
 * below it, actions in the middle column, lists on the right.
 *
 * The gate is crew.is_admin rather than crew.id -- everything past it reads
 * across every client's money. It is a courtesy, not the boundary: AdminPortal
 * re-checks isAdmin() server-side on every action, so a flow wired wrongly
 * refuses rather than leaks.
 *
 * Triggered by the word "studio" rather than a greeting, so it can sit
 * alongside the crew flow without the two fighting over "hi".
 */
$r = 140; $A = 60; $B = 460; $C = 860;

$menu = "1|Money|Collected this month, and what's outstanding\n"
      . "2|Overdue|Invoices past their due date, worst first\n"
      . "3|Today's shoots|What's on, where, and who's crewed\n"
      . "4|Timesheet gaps|Who didn't log yesterday";

$parse = fn (string $block) => array_values(array_map(function (string $line) {
    [$id, $title, $description] = array_pad(explode('|', $line, 3), 3, '');
    return ['id' => trim($id), 'title' => trim($title), 'description' => trim($description)];
}, array_filter(preg_split('/\n/', $block))));

$list = fn (string $body, int $row) => [
    'type' => 'send_list', 'body' => $body, 'rows' => $parse($menu), 'button' => 'Choose',
    'header' => '', 'footer' => '', 'next' => null,
    '_pos' => ['x' => $C, 'y' => 40 + $r * $row],
];

$nodes = [
    '1' => ['type' => 'condition', 'variable' => 'crew.is_admin', 'operator' => 'equals', 'value' => 'true',
            'next_true' => '2', 'next_false' => '30', '_pos' => ['x' => $A, 'y' => 40]],

    '2' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '1',
            'next_true' => '20', 'next_false' => '3', '_pos' => ['x' => $A, 'y' => 40 + $r]],
    '3' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '2',
            'next_true' => '21', 'next_false' => '4', '_pos' => ['x' => $A, 'y' => 40 + $r * 2]],
    '4' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '3',
            'next_true' => '22', 'next_false' => '5', '_pos' => ['x' => $A, 'y' => 40 + $r * 3]],
    '5' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '4',
            'next_true' => '23', 'next_false' => '6', '_pos' => ['x' => $A, 'y' => 40 + $r * 4]],

    '6' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'studio',
            'next_true' => '25', 'next_false' => '27', '_pos' => ['x' => $A, 'y' => 40 + $r * 5]],

    '20' => ['type' => 'admin_action', 'action' => 'money', 'next' => '28', '_pos' => ['x' => $B, 'y' => 40 + $r]],
    '21' => ['type' => 'admin_action', 'action' => 'overdue', 'next' => '28', '_pos' => ['x' => $B, 'y' => 40 + $r * 2]],
    '22' => ['type' => 'admin_action', 'action' => 'todays_shoots', 'next' => '28', '_pos' => ['x' => $B, 'y' => 40 + $r * 3]],
    '23' => ['type' => 'admin_action', 'action' => 'timesheet_gaps', 'next' => '28', '_pos' => ['x' => $B, 'y' => 40 + $r * 4]],

    '25' => $list("{{crew.name}} — what do you want to see?", 5),
    '27' => $list("I didn't catch that — pick one below, or type 1-4:", 6),
    '28' => $list('Anything else?', 1),

    '30' => ['type' => 'send_message',
             'body' => 'Thanks for your message — someone from Chakra Groups will reply here shortly.',
             'next' => null, '_pos' => ['x' => $B, 'y' => 40]],
];

return ['start_node_id' => '1', 'nodes' => $nodes];
