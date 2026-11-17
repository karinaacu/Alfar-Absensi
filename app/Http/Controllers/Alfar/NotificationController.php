<?php

namespace App\Http\Controllers\Alfar;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json(DB::table('employee_notifications')->where('user_id', $r->user()->id)->orderByDesc('id')->paginate(20))->header('Cache-Control', 'no-store');
    }

    public function feed(Request $r): JsonResponse
    {
        $r->validate(['after' => 'sometimes|integer|min:0']);
        $query = DB::table('employee_notifications')->where('user_id', $r->user()->id);
        $items = $r->has('after') ? (clone $query)->where('id', '>', $r->integer('after'))->orderBy('id')->limit(20)->get() : collect();
        $cursor = $r->has('after') ? ($items->last()->id ?? $r->integer('after')) : ((clone $query)->max('id') ?? 0);

        return response()->json(['items' => $items, 'cursor' => (int) $cursor, 'unread' => (clone $query)->whereNull('read_at')->count()])->header('Cache-Control', 'no-store');
    }

    public function read(Request $r, int $id): JsonResponse
    {
        $query = DB::table('employee_notifications')->where('user_id', $r->user()->id)->where('id', $id);
        abort_unless((clone $query)->exists(), 404, 'Notifikasi tidak ditemukan.');
        $query->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Notifikasi ditandai sudah dibaca.']);
    }

    public function readAll(Request $r): JsonResponse
    {
        DB::table('employee_notifications')->where('user_id', $r->user()->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Semua notifikasi ditandai sudah dibaca.']);
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $deleted = DB::table('employee_notifications')->where('user_id', $r->user()->id)->where('id', $id)->delete();
        abort_unless($deleted > 0, 404, 'Notifikasi tidak ditemukan.');

        return response()->json(['message' => 'Notifikasi dihapus.']);
    }

    public function destroyAll(Request $r): JsonResponse
    {
        DB::table('employee_notifications')->where('user_id', $r->user()->id)->delete();

        return response()->json(['message' => 'Semua notifikasi dihapus.']);
    }
}
