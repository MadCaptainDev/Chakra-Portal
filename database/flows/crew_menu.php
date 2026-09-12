<?php
/*
 * Crew menu.
 *
 * One gate at the top, then a ladder. Node 1 asks whether this number belongs
 * to somebody at the studio; everything below it is therefore staff-only, and
 * a stranger never reaches an action node at all -- they get one polite line
 * that gives away nothing about what lives here.
 *
 * Layout: column A (x=60) every test, one per row, read top to bottom;
 * column B (x=460) what each test reaches; column C (x=860) the list that
 * closes an answered question.
 *
 * Kit flagging is deliberately NOT a menu row. CrewPortal::flagKit reads the
 * item name out of the message itself, and a tapped row sends only its id --
 * "3" names no tripod. So the kit paths match the words people actually use
 * ("the gimbal is broken"), which carry the item name with them, and the list
 * footer points at that rather than offering a row that cannot work.
 */
$r = 140; $A = 60; $B = 460; $C = 860;

$menu = "1|My shoots|Call time, location, who else is on\n"
      . "2|Confirm call time|Tell the producer you're coming\n"
      . "3|Talk to someone|Hand over to a human";

$footer = 'Kit broken? Just text me, e.g. "broken tripod"';

$parse = fn (string $block) => array_values(array_map(function (string $line) {
    [$id, $title, $description] = array_pad(explode('|', $line, 3), 3, '');
    return ['id' => trim($id), 'title' => trim($title), 'description' => trim($description)];
}, array_filter(preg_split('/\n/', $block))));

$list = fn (string $body, int $row) => [
    'type' => 'send_list', 'body' => $body, 'rows' => $parse($menu), 'button' => 'Choose',
    'header' => '', 'footer' => $footer, 'next' => null,
    '_pos' => ['x' => $C, 'y' => 40 + $r * $row],
];

$nodes = [
    // ——— the gate ———
    '1' => ['type' => 'condition', 'variable' => 'crew.id', 'operator' => 'exists', 'value' => '',
            'next_true' => '2', 'next_false' => '30', '_pos' => ['x' => $A, 'y' => 40]],

    // ——— menu choices ———
    '2' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '1',
            'next_true' => '20', 'next_false' => '3', '_pos' => ['x' => $A, 'y' => 40 + $r]],
    '3' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '2',
            'next_true' => '21', 'next_false' => '4', '_pos' => ['x' => $A, 'y' => 40 + $r * 2]],
    '4' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '3',
            'next_true' => '24', 'next_false' => '5', '_pos' => ['x' => $A, 'y' => 40 + $r * 3]],

    // ——— typed kit reports, ahead of the greetings: "broken" is a statement,
    //     not a greeting, and it is the message carrying the item name ———
    '5' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'broken',
            'next_true' => '22', 'next_false' => '6', '_pos' => ['x' => $A, 'y' => 40 + $r * 4]],
    '6' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'damaged',
            'next_true' => '22', 'next_false' => '7', '_pos' => ['x' => $A, 'y' => 40 + $r * 5]],
    '7' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'missing',
            'next_true' => '23', 'next_false' => '8', '_pos' => ['x' => $A, 'y' => 40 + $r * 6]],
    '8' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'lost',
            'next_true' => '23', 'next_false' => '9', '_pos' => ['x' => $A, 'y' => 40 + $r * 7]],

    // ——— greetings, then the catch-all ———
    '9'  => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'hi',
             'next_true' => '25', 'next_false' => '10', '_pos' => ['x' => $A, 'y' => 40 + $r * 8]],
    '10' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'menu',
             'next_true' => '25', 'next_false' => '27', '_pos' => ['x' => $A, 'y' => 40 + $r * 9]],

    // ——— actions ———
    '20' => ['type' => 'crew_action', 'action' => 'my_shoots', 'next' => '28', '_pos' => ['x' => $B, 'y' => 40 + $r]],
    '21' => ['type' => 'crew_action', 'action' => 'confirm_call_time', 'next' => '28', '_pos' => ['x' => $B, 'y' => 40 + $r * 2]],
    '22' => ['type' => 'crew_action', 'action' => 'flag_damaged', 'next' => '28', '_pos' => ['x' => $B, 'y' => 40 + $r * 4]],
    '23' => ['type' => 'crew_action', 'action' => 'flag_missing', 'next' => '28', '_pos' => ['x' => $B, 'y' => 40 + $r * 6]],
    '24' => ['type' => 'agent_transfer', 'user_id' => null, '_pos' => ['x' => $B, 'y' => 40 + $r * 3]],

    // ——— what gets sent ———
    '25' => $list("Hi {{crew.name}} — what do you need?", 8),
    '27' => $list("I didn't catch that — pick one below, or type 1-3:", 9),
    '28' => $list('Anything else?', 1),

    // The only thing a number we do not recognise ever sees.
    '30' => ['type' => 'send_message',
             'body' => 'Thanks for your message — someone from Chakra Groups will reply here shortly.',
             'next' => null, '_pos' => ['x' => $B, 'y' => 40]],
];

return ['start_node_id' => '1', 'nodes' => $nodes];
