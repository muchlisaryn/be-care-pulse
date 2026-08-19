<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\MemberStatus;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master status anggota.
 *
 * Tabel & kolomnya berbahasa Inggris (`member_statuses`), sedangkan kontrak API tetap
 * memakai `kode` & `nama` — penerjemahannya ditangani model MemberStatus.
 * URL tetap memakai kode (MemberStatus::getRouteKeyName), bukan id.
 */
class StatusAnggotaController extends Controller
{
    use RecreatesSoftDeleted;

    public function index(Request $request)
    {
        $query = MemberStatus::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all')) {
            return response()->json($query->orderBy('code')->get());
        }

        return response()->json($query->orderBy('code')->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:50', Rule::unique('member_statuses', 'code')->whereNull('deleted_by')],
            'nama' => ['required', 'string', 'max:255'],
        ]);

        $memberStatus = $this->createOrRestore(MemberStatus::class, 'code', MemberStatus::fromLegacy($data));

        return response()->json($memberStatus, 201);
    }

    public function show(MemberStatus $memberStatus)
    {
        return response()->json($memberStatus);
    }

    public function update(Request $request, MemberStatus $memberStatus)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
        ]);

        $memberStatus->update(MemberStatus::fromLegacy($data));

        return response()->json($memberStatus);
    }

    public function destroy(MemberStatus $memberStatus)
    {
        $memberStatus->delete();

        return response()->json(['message' => 'Status anggota dihapus.']);
    }
}
