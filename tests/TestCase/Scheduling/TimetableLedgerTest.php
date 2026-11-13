<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Scheduling\TimetableLedger;
use PHPUnit\Framework\TestCase;

class TimetableLedgerTest extends TestCase
{
    public function testOwnSessionIsNotCountedAgainstItsOwnLane(): void
    {
        $ledger = new TimetableLedger(0);
        $ledger->book(1, '2026-10-27', 480, 510, 100, [10], [], 'batch-a', 1);
        $this->assertNotNull($ledger->roomBlock(1, '2026-10-27', 480, 510, 100, 1));
        $this->assertNull($ledger->roomBlock(1, '2026-10-27', 480, 510, 100, 1, 'batch-a'));
        $this->assertNotNull($ledger->roomBlock(1, '2026-10-27', 480, 510, 100, 1, 'batch-b'));
    }

    private const DATE = '2026-10-27';

    public function testStudentCannotBeBookedTwiceAtOverlappingTimes(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(1, self::DATE, '09:00', '09:30', 100, [7]);

        $this->assertSame([7 => 570], $ledger->studentBlocks([7], self::DATE, '09:15', '09:45'));
        $this->assertSame([], $ledger->studentBlocks([7], self::DATE, '09:30', '10:00'), 'back-to-back is allowed with a 0 minute buffer');
        $this->assertSame([], $ledger->studentBlocks([7], '2026-10-28', '09:15', '09:45'), 'other days are independent');
        $this->assertSame([], $ledger->studentBlocks([8], self::DATE, '09:15', '09:45'));
    }

    public function testHardTransitionBufferIsApplied(): void
    {
        $ledger = new TimetableLedger(10);
        $ledger->book(1, self::DATE, '09:00', '09:30', 100, [7]);

        $this->assertSame([7 => 580], $ledger->studentBlocks([7], self::DATE, '09:35', '10:00'));
        $this->assertSame([], $ledger->studentBlocks([7], self::DATE, '09:40', '10:00'));
    }

    public function testGroupMembersAreAllChecked(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(1, self::DATE, '09:00', '10:00', 100, [1, 2, 3]);

        $this->assertSame([3 => 600], $ledger->studentBlocks([3, 4, 5], self::DATE, '09:30', '10:30'));
    }

    public function testDifferentEventsCannotShareARoom(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(5, self::DATE, '09:00', '09:30', 100, [1]);

        $block = $ledger->roomBlock(5, self::DATE, '09:15', '09:45', 200, 4);
        $this->assertSame(['reason' => 'room_other_event', 'next' => 570], $block);
        $this->assertNull($ledger->roomBlock(5, self::DATE, '09:30', '10:00', 200));
        $this->assertNull($ledger->roomBlock(6, self::DATE, '09:15', '09:45', 200));
    }

    public function testSameEventMayUseEveryLaneButNoMore(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(5, self::DATE, '09:00', '09:30', 100, [1, 2]);
        $ledger->book(5, self::DATE, '09:00', '09:30', 100, [3, 4]);

        $this->assertNull($ledger->roomBlock(5, self::DATE, '09:00', '09:30', 100, 3));
        $this->assertSame('room_full', $ledger->roomBlock(5, self::DATE, '09:00', '09:30', 100, 2)['reason']);
        $this->assertSame('room_full', $ledger->roomBlock(5, self::DATE, '09:10', '09:40', 100, 1)['reason']);
    }

    public function testSingleBoardSessionKeepsTheRoomForItselfInEitherOrder(): void
    {
        // Single-board round booked first: a normal 5-lane game may not join it.
        $ledger = new TimetableLedger();
        $ledger->book(5, self::DATE, '09:20', '09:30', 100, [1, 2], [], null, 1);
        $block = $ledger->roomBlock(5, self::DATE, '09:25', '09:35', 100, 5);
        $this->assertSame('room_full', $block['reason']);
        $this->assertSame(570, $block['next']);
        $this->assertNull($ledger->roomBlock(5, self::DATE, '09:30', '09:40', 100, 5));

        // Normal game booked first: the single-board round may not join it either.
        $ledger = new TimetableLedger();
        $ledger->book(5, self::DATE, '09:25', '09:35', 100, [3, 4], [], null, 5);
        $this->assertSame('room_full', $ledger->roomBlock(5, self::DATE, '09:20', '09:30', 100, 1)['reason']);
        $this->assertNull($ledger->roomBlock(5, self::DATE, '09:20', '09:30', 100, 5), 'other 5-lane games still share');
    }

    public function testReleasingSingleBoardSessionFreesTheLanes(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(5, self::DATE, '09:20', '09:30', 100, [1, 2], [], 'final', 1);
        $ledger->release('final', [1, 2]);
        $this->assertNull($ledger->roomBlock(5, self::DATE, '09:25', '09:35', 100, 5));
    }

    public function testLaneCountUsesPeakConcurrencyNotTotalOverlaps(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(5, self::DATE, '09:00', '09:30', 100, [1]);
        $ledger->book(5, self::DATE, '09:30', '10:00', 100, [2]);

        // 09:15-09:45 overlaps both sessions, but never more than one at a time.
        $this->assertNull($ledger->roomBlock(5, self::DATE, '09:15', '09:45', 100, 2));
    }

    public function testSharedSessionKeyUsesOneLane(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(5, self::DATE, '09:00', '09:30', 100, [1], [], 'combined');
        $ledger->book(5, self::DATE, '09:00', '09:30', 100, [2], [], 'combined');

        $this->assertNull($ledger->roomBlock(5, self::DATE, '09:00', '09:30', 100, 2));
        $this->assertSame([2 => 570], $ledger->studentBlocks([2], self::DATE, '09:00', '09:30'));
    }

    public function testZeroLengthBookingsAreIgnored(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(5, self::DATE, '09:00', '09:00', 100, [1]);

        $this->assertNull($ledger->roomBlock(5, self::DATE, '08:30', '09:30', 200));
        $this->assertSame([], $ledger->studentBlocks([1], self::DATE, '08:30', '09:30'));
    }

    public function testReleaseRemovesSharedSessionOnlyAfterLastReference(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(5, self::DATE, '09:00', '09:30', 100, [1], [], 'shared');
        $ledger->book(5, self::DATE, '09:00', '09:30', 100, [2], [], 'shared');

        $ledger->release('shared', [1]);
        $this->assertNotEmpty($ledger->roomSessions(5, self::DATE));
        $this->assertSame([], $ledger->studentBlocks([1], self::DATE, '09:00', '09:10'));
        $this->assertNotSame([], $ledger->studentBlocks([2], self::DATE, '09:00', '09:10'));

        $ledger->release('shared', [2]);
        $this->assertSame([], $ledger->roomSessions(5, self::DATE));
    }

    public function testSoftPenaltyCountsBackToBackAndPossibleOverlaps(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(1, self::DATE, '09:00', '09:30', 100, [7], [9]);

        $this->assertSame(1, $ledger->softPenalty([7], [], self::DATE, '09:30', '10:00', 5), 'no gap after 09:30');
        $this->assertSame(0, $ledger->softPenalty([7], [], self::DATE, '09:35', '10:00', 5));
        $this->assertSame(1, $ledger->softPenalty([9], [], self::DATE, '09:15', '09:45', 5), 'overlaps a possible booking');
        $this->assertSame(1, $ledger->softPenalty([], [7], self::DATE, '09:15', '09:45', 5), 'possible participant overlaps a confirmed booking');
        $this->assertSame(0, $ledger->softPenalty([], [7], self::DATE, '09:30', '10:00', 5), 'possible participants are only checked for overlap');
        $this->assertSame(0, $ledger->softPenalty([], [9], self::DATE, '09:00', '09:30', 5, 100), 'same-event possible overlaps (Final and 3rd place) are ignored');
        $this->assertSame(1, $ledger->softPenalty([], [9], self::DATE, '09:00', '09:30', 5, 200));
    }

    public function testTimeConversion(): void
    {
        $this->assertSame(510, TimetableLedger::toMinutes('08:30:00'));
        $this->assertSame('13:05:00', TimetableLedger::toTime(785));
    }
}
