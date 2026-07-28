<?php

namespace App\Http\Controllers;

use App\Models\AssetCategory;
use App\Models\CustomerType;
use App\Models\VendorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Controller generik untuk master "jenis" sederhana yang sebentuk: satu PK kode,
 * satu nama, status (+opsional keterangan). Konfigurasi dipetakan dari segmen
 * pertama rute. Menghindari menggandakan controller/view yang nyaris identik.
 */
class SimpleMasterController extends Controller
{
    /** @var array<string,array{model:class-string<Model>,pk:string,label:string,route:string,keterangan:bool}> */
    private const CONF = [
        'vendor-types' => ['model' => VendorType::class, 'pk' => 'kode_jenis_vendor', 'label' => 'Jenis Vendor', 'route' => 'vendor_types', 'keterangan' => false],
        'customer-types' => ['model' => CustomerType::class, 'pk' => 'kode_jenis_customer', 'label' => 'Jenis Customer', 'route' => 'customer_types', 'keterangan' => false],
        'asset-categories' => ['model' => AssetCategory::class, 'pk' => 'kode_kategori', 'label' => 'Kategori Aset', 'route' => 'asset_categories', 'keterangan' => true],
    ];

    private function conf(Request $request): array
    {
        $kode = $request->segment(1);
        abort_unless(isset(self::CONF[$kode]), 404);

        return [...self::CONF[$kode], 'kode' => $kode];
    }

    public function index(Request $request): View
    {
        $c = $this->conf($request);
        $q = trim((string) $request->query('q', ''));
        $model = $c['model'];

        $rows = $model::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where($c['pk'], 'ilike', "%{$q}%")->orWhere('nama', 'ilike', "%{$q}%"),
            ))
            ->orderBy($c['pk'])
            ->get();

        return view('simple-master.index', ['rows' => $rows, 'q' => $q, 'c' => $c]);
    }

    public function create(Request $request): View
    {
        $c = $this->conf($request);

        return view('simple-master.form', ['row' => new $c['model'](['status' => 'aktif']), 'baru' => true, 'c' => $c]);
    }

    public function store(Request $request): RedirectResponse
    {
        $c = $this->conf($request);
        $data = $this->validasi($request, $c, true);

        $c['model']::create($data);

        return redirect()->route("{$c['route']}.index")->with('status', "{$c['label']} berhasil ditambahkan.");
    }

    public function edit(Request $request, string $id): View
    {
        $c = $this->conf($request);
        $row = $this->cari($c, $id);

        return view('simple-master.form', ['row' => $row, 'baru' => false, 'c' => $c]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $c = $this->conf($request);
        $row = $this->cari($c, $id);
        $data = $this->validasi($request, $c, false);

        $row->update($data);

        return redirect()->route("{$c['route']}.index")->with('status', "{$c['label']} berhasil diperbarui.");
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $c = $this->conf($request);
        $row = $this->cari($c, $id);

        try {
            $row->delete();
        } catch (QueryException $e) {
            return redirect()->route("{$c['route']}.index")->with('error', "{$c['label']} tidak bisa dihapus karena masih dipakai data lain.");
        }

        return redirect()->route("{$c['route']}.index")->with('status', "{$c['label']} berhasil dihapus.");
    }

    private function cari(array $c, string $id): Model
    {
        return $c['model']::where($c['pk'], $id)->firstOrFail();
    }

    private function validasi(Request $request, array $c, bool $isCreate): array
    {
        $rules = [
            'nama' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
        if ($c['keterangan']) {
            $rules['keterangan'] = ['nullable', 'string'];
        }
        if ($isCreate) {
            $rules[$c['pk']] = ['required', 'string', 'max:255', Rule::unique((new $c['model'])->getTable(), $c['pk'])];
        }

        $data = $request->validate($rules);
        if (! $isCreate) {
            unset($data[$c['pk']]);
        }

        return $data;
    }
}
