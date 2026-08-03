<?php

namespace Tests\Unit;

use Modules\Project\Support\Position;
use PHPUnit\Framework\TestCase;

/**
 * Fractional ordering.
 *
 * The property that matters is that a value placed between two others is
 * strictly between them, every time, for as long as the gap allows — and that
 * the moment it no longer does, the caller is told rather than silently
 * writing a duplicate.
 */
class PositionTest extends TestCase
{
    public function test_a_midpoint_lies_strictly_between_its_neighbours(): void
    {
        $mid = Position::between('1024.0000000000', '2048.0000000000');

        $this->assertSame('1536.0000000000', $mid);
    }

    public function test_appending_leaves_room_after_the_last_card(): void
    {
        $this->assertSame('1024.0000000000', Position::after(null));
        $this->assertSame('2048.0000000000', Position::after('1024.0000000000'));
    }

    public function test_prepending_leaves_room_before_the_first_card(): void
    {
        $this->assertSame('512.0000000000', Position::before('1024.0000000000'));
    }

    public function test_an_open_end_extends_the_list_rather_than_halving(): void
    {
        $this->assertSame('2048.0000000000', Position::between('1024.0000000000', null));
        $this->assertSame('512.0000000000', Position::between(null, '1024.0000000000'));
        $this->assertSame('1024.0000000000', Position::between(null, null));
    }

    /**
     * The whole point of the design: dropping repeatedly into the same slot
     * must keep producing a value between the same two neighbours, not a
     * collision, until the gap is genuinely spent.
     */
    public function test_repeated_insertion_in_the_same_place_never_collides(): void
    {
        $low = '0.0000000000';
        $high = '1024.0000000000';

        $seen = [];

        for ($i = 0; $i < 20; $i++) {
            if (Position::needsRebalance($low, $high)) {
                break;
            }

            $mid = Position::between($low, $high);

            $this->assertGreaterThan((float) $low, (float) $mid, "iteration {$i} did not stay above its lower neighbour");
            $this->assertLessThan((float) $high, (float) $mid, "iteration {$i} did not stay below its upper neighbour");
            $this->assertNotContains($mid, $seen, "iteration {$i} repeated a position");

            $seen[] = $mid;
            $high = $mid;
        }

        $this->assertGreaterThanOrEqual(20, count($seen), 'the gap ran out sooner than the design allows');
    }

    public function test_a_spent_gap_asks_for_a_rebalance_instead_of_colliding(): void
    {
        $this->assertFalse(Position::needsRebalance('1024.0000000000', '2048.0000000000'));
        $this->assertTrue(Position::needsRebalance('1024.0000000000', '1024.0000100000'));
    }

    public function test_an_open_end_never_needs_a_rebalance(): void
    {
        $this->assertFalse(Position::needsRebalance(null, '1024.0000000000'));
        $this->assertFalse(Position::needsRebalance('1024.0000000000', null));
    }

    public function test_a_spread_is_evenly_spaced_and_ascending(): void
    {
        $spread = Position::spread(4);

        $this->assertCount(4, $spread);
        $this->assertSame('1024.0000000000', $spread[0]);
        $this->assertSame('4096.0000000000', $spread[3]);

        for ($i = 1; $i < count($spread); $i++) {
            $this->assertGreaterThan((float) $spread[$i - 1], (float) $spread[$i]);
        }
    }

    public function test_an_empty_list_spreads_to_nothing_usable_rather_than_erroring(): void
    {
        $this->assertSame([], Position::spread(0));
    }

    /**
     * No float may appear in the ordering path. A position that arrives as
     * `1.0E+25` or loses its last digits to binary rounding is a list whose
     * order is whatever the database feels like that day.
     */
    public function test_every_value_is_a_fixed_scale_decimal_string(): void
    {
        $values = [
            Position::after('1024'),
            Position::before('1024'),
            Position::between('1', '2'),
            ...Position::spread(3),
        ];

        foreach ($values as $value) {
            $this->assertIsString($value);
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{10}$/', $value, $value.' is not a decimal(20,10) string');
        }
    }
}
