<?php

use PHPUnit\Framework\TestCase;

// The engine file only calls WP/WC functions inside method bodies, so it loads
// fine here; ABSPATH is defined by the unit bootstrap.
require_once __DIR__ . '/../../modules/woo-sheets-sync/includes/class-wss-sync-engine.php';

/**
 * @covers WSS_Sync_Engine::decide_stock_direction
 *
 * Exercises the bidirectional stock reconciliation matrix that fixes the
 * "nightly sync reverts every sale" bug. Mirrors acceptance tests T1–T6.
 */
class StockDirectionTest extends TestCase
{
    /** Neither side moved since last sync → no-op (T6: no spurious writes). */
    public function test_neither_moved_is_noop()
    {
        $d = WSS_Sync_Engine::decide_stock_direction(4, 4, 4, 4, false);
        $this->assertFalse($d['apply_stock']);
        $this->assertFalse($d['conflict']);
        $this->assertSame(4, $d['final_qty']);
    }

    /** Sheet edited, Woo unchanged → apply Sheet→Woo (T2: manual control). */
    public function test_sheet_moved_only_applies_to_woo()
    {
        // After T1 both sides agreed at 4; client edits Sheet to 10.
        $d = WSS_Sync_Engine::decide_stock_direction(4, 10, 4, 4, false);
        $this->assertTrue($d['apply_stock']);
        $this->assertFalse($d['conflict']);
        $this->assertSame(10, $d['final_qty']);
    }

    /**
     * Woo reduced by an order, Sheet still showing the old qty → keep Woo and
     * push Woo→Sheet. This is the core regression: the sale must NOT be
     * reverted (T1 recovery / realtime-off path).
     */
    public function test_woo_moved_only_keeps_woo_and_pushes()
    {
        $d = WSS_Sync_Engine::decide_stock_direction(4, 5, 5, 5, false);
        $this->assertFalse($d['apply_stock']); // do not overwrite Woo from the stale sheet
        $this->assertFalse($d['conflict']);
        $this->assertSame(4, $d['final_qty']); // Phase 2 will write 4 to the sheet
    }

    /** Both sides changed → conflict, Woo wins for qty (T4). */
    public function test_both_moved_is_conflict_woo_wins()
    {
        // Order made Woo 5→4 while the client edited the Sheet to 12.
        $d = WSS_Sync_Engine::decide_stock_direction(4, 12, 5, 5, false);
        $this->assertFalse($d['apply_stock']);
        $this->assertTrue($d['conflict']);
        $this->assertSame(4, $d['final_qty']); // Woo wins
    }

    /** Migration (no snapshots) with a pending sheet edit → Sheet wins, seed. */
    public function test_migration_applies_pending_sheet_edit()
    {
        $d = WSS_Sync_Engine::decide_stock_direction(5, 10, null, null, false);
        $this->assertTrue($d['apply_stock']);
        $this->assertFalse($d['conflict']);
        $this->assertSame(10, $d['final_qty']);
    }

    /** Migration with equal values → no-op, seed snapshots to current. */
    public function test_migration_equal_is_noop()
    {
        $d = WSS_Sync_Engine::decide_stock_direction(5, 5, null, null, false);
        $this->assertFalse($d['apply_stock']);
        $this->assertFalse($d['conflict']);
        $this->assertSame(5, $d['final_qty']);
    }

    /** Migration with a blank sheet qty cell → no-op, keep Woo. */
    public function test_migration_blank_sheet_qty_keeps_woo()
    {
        $d = WSS_Sync_Engine::decide_stock_direction(5, null, null, null, false);
        $this->assertFalse($d['apply_stock']);
        $this->assertSame(5, $d['final_qty']);
    }

    /** Blank sheet qty cannot count as "sheet moved"; a Woo move still pushes. */
    public function test_blank_sheet_qty_with_snapshots_lets_woo_push()
    {
        $d = WSS_Sync_Engine::decide_stock_direction(4, null, 5, 5, false);
        $this->assertFalse($d['apply_stock']);
        $this->assertFalse($d['conflict']);
        $this->assertSame(4, $d['final_qty']);
    }

    /** On migration, a stock_status difference alone triggers an apply. */
    public function test_migration_status_difference_triggers_apply()
    {
        $d = WSS_Sync_Engine::decide_stock_direction(5, 5, null, null, true);
        $this->assertTrue($d['apply_stock']);
        $this->assertSame(5, $d['final_qty']);
    }

    /**
     * Sequence T1→T2 at the decision level: after a realtime push the snapshots
     * are 4/4; a later sheet edit to 10 then wins.
     */
    public function test_sequence_realtime_then_sheet_edit()
    {
        // T1 settled at 4/4 (realtime push). No change → no-op.
        $settled = WSS_Sync_Engine::decide_stock_direction(4, 4, 4, 4, false);
        $this->assertFalse($settled['apply_stock']);

        // T2: Sheet → 10.
        $edited = WSS_Sync_Engine::decide_stock_direction(4, 10, 4, 4, false);
        $this->assertTrue($edited['apply_stock']);
        $this->assertSame(10, $edited['final_qty']);
    }
}
