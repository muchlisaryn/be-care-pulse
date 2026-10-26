<?php

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\Instrument;
use App\Models\InstrumentStock;
use App\Models\InstrumentStorage;
use App\Models\OrderWashing;
use App\Models\Packaging;
use App\Models\PackagingType;
use App\Models\Room;
use App\Models\Sterilization;
use App\Models\SterilizerMachine;
use App\Models\User;
use App\Models\WasherMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Alur instrumen dari ujung ke ujung: Produksi → Cleaning → Packaging →
 * Sterilisasi (steril) → Simpan ke Gudang Steril → Order.
 */
class InstrumentPipelineFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_until_sterile_then_stored_and_ordered(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Petugas CSSD']), 'sanctum');

        $condition = Condition::create(['name' => 'Baik']);
        $instrument = Instrument::create(['code' => 'GNT', 'name' => 'Gunting']);
        $stocks = collect(range(1, 2))->map(fn () => InstrumentStock::create([
            'instrument_id' => $instrument->id,
            'condition_id' => $condition->id,
            'status' => InstrumentStock::STATUS_TERSEDIA,
        ]));
        $washer = WasherMachine::create(['name' => 'Washer 1', 'status' => 'aktif']);
        $sterilizer = SterilizerMachine::create(['code' => 'AUT01', 'name' => 'Autoclave 1', 'status' => 'aktif']);
        $packagingType = PackagingType::create(['code' => 'PCH', 'name' => 'Pouch', 'shelf_life_days' => 30]);
        $room = Room::create(['name' => 'Poli Bedah', 'layanan' => 'rawat_jalan']);

        // 1. Produksi — kunci 2 unit, otomatis membuka batch cleaning.
        $this->postJson('/api/master/production', [
            'items' => [['type' => 'satuan', 'instrument_id' => $instrument->id, 'quantity' => 2]],
        ])->assertCreated();

        $washing = OrderWashing::latest('id')->firstOrFail();

        // 2. Cleaning — isi parameter pencucian, lalu tandai selesai.
        $this->putJson("/api/master/cleaning/{$washing->id}/washing", [
            'washer_machine_id' => $washer->id,
            'temperature' => '90',
            'washed_at' => now()->toDateTimeString(),
            'duration_minutes' => 30,
            'detergent_type' => 'Enzimatik',
        ])->assertOk();
        $this->putJson("/api/master/cleaning/{$washing->id}/washing", ['complete' => true])->assertOk();
        $this->assertSame(OrderWashing::STATUS_SELESAI, $washing->fresh()->status);

        // 3. Packaging — selesai & cetak label.
        $this->postJson('/api/master/packaging/complete', [
            'washing_code' => $washing->code,
            'chemical_indicator' => 'Berhasil',
            'packaging_type_id' => $packagingType->id,
        ])->assertSuccessful();

        $packaging = Packaging::where('washing_code', $washing->code)->firstOrFail();
        $barcodes = $packaging->items()->pluck('barcode_no')->unique()->values()->all();
        $this->assertNotEmpty($barcodes);

        // 4. Sterilisasi — gabung label ke satu batch; kodenya ikut format BMHP.
        $batch = $this->postJson('/api/master/sterilization-pipeline/batch', [
            'barcode_nos' => $barcodes,
            'machine' => $sterilizer->name,
            'sterilized_at' => now()->toDateTimeString(),
        ])->assertSuccessful()->json('data');

        $this->assertMatchesRegularExpression('/^AUT01-'.now()->format('dmy').'-01$/', $batch['code']);
        $sterilization = Sterilization::where('code', $batch['code'])->firstOrFail();

        // 5. Validasi — semua unit lolos → batch steril.
        $this->postJson("/api/master/sterilization-pipeline/{$sterilization->id}/validate", [
            'failed_stock_ids' => [],
            'chemical_indicator' => 'Berhasil',
            'bio_indicator_test' => 'Negatif',
        ])->assertOk();
        $this->assertSame(Sterilization::STATUS_SELESAI, $sterilization->fresh()->status);

        // 6. Simpan ke Gudang Steril.
        $this->postJson("/api/master/sterilization/{$sterilization->id}/store", [
            'items' => $stocks->map(fn ($s) => ['instrument_stock_id' => $s->id, 'rack_code' => 'RAK-A1'])->all(),
        ])->assertSuccessful();
        $this->assertSame(2, InstrumentStorage::where('sterilization_id', $sterilization->id)->count());

        // 7. Order — stok steril di gudang cukup untuk dipesan.
        $this->postJson('/api/master/orders', [
            'room_id' => $room->id,
            'order_date' => now()->toDateString(),
            'order_time' => '09:00',
            'items' => [['type' => 'satuan', 'instrument_id' => $instrument->id, 'quantity' => 1]],
        ])->assertCreated();
    }

    public function test_instrument_and_bmhp_sterilization_share_the_daily_sequence(): void
    {
        $day = now()->format('dmy');
        SterilizerMachine::create(['code' => 'AUT01', 'name' => 'Autoclave 1', 'status' => 'aktif']);

        $first = Sterilization::create(['machine' => 'Autoclave 1', 'sterilized_at' => now(), 'status' => 'diproses']);
        $this->assertSame("AUT01-{$day}-01", $first->code);

        // Mesin di luar master → awalan STR, nomor urut tetap berlanjut.
        $second = Sterilization::create(['machine' => 'Mesin Lain', 'sterilized_at' => now(), 'status' => 'diproses']);
        $this->assertSame("STR-{$day}-02", $second->code);

        $this->assertSame("AUT01-{$day}-03", \App\Models\SterilizationBmhp::nextCode('AUT01'));
    }
}
