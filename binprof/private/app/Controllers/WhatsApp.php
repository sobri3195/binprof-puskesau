<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/WhatsApp/link.php';
require_once PROJECT . '/private/app/Services/WhatsApp/meta.php';
require_once PROJECT . '/private/app/Services/maps.php';

function whatsappSettings(array $user): void
{
    if (!waMigrated()) {
        fail(503,'Migrasi WhatsApp belum diterapkan.');
    }
    $link = waLinkForUser($user['id']);
    $valid = $link && waActor($link) !== null;
    $challenge = qu('SELECT id,identity_hint,expires_at FROM whatsapp_link_challenges
        WHERE user_id=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP() ORDER BY created_at DESC LIMIT 1',
        [$user['id']])->fetch();
    $code = $_SESSION['whatsapp_code'] ?? null;
    unset($_SESSION['whatsapp_code']);
    $pref = mapPreferences($user['id']);
    $history = qu('SELECT o.kind,o.state,o.created_at FROM whatsapp_outbox o JOIN whatsapp_links l ON l.id=o.link_id
        WHERE l.user_id=? ORDER BY o.created_at DESC,o.id DESC LIMIT 10',[$user['id']])->fetchAll();
    page('Pengaturan WhatsApp',static function () use ($link,$valid,$challenge,$code,$pref,$history): void {
        ?>
        <section class="surface">
            <h2>Penautan akun</h2>
            <p><?= e(waTransportReady() ? 'Konfigurasi Cloud API tersedia.' : 'Pengiriman Cloud API belum dikonfigurasi.') ?></p>
            <?php if ($link): ?>
                <p>Status: <?= e($valid ? 'Tertaut' : 'Perlu ditautkan ulang karena hak atau sesi berubah') ?> · <?= e($link['identity_hint']) ?></p>
                <?php postButton(WA_SETTINGS . '/unlink','Putuskan tautan'); ?>
            <?php else: ?><p>Belum ada identitas WhatsApp tertaut.</p><?php endif; ?>
            <?php if ($code && $challenge): ?>
                <p>Kirim <strong>TAUT <?= e($code) ?></strong> ke nomor WhatsApp resmi BINPROFKES dalam 10 menit.</p>
                <?php if (($bot = waConfiguredBotLink()) !== null): ?>
                    <p><a href="<?= e($bot) ?>" rel="noreferrer">Buka WhatsApp resmi BINPROFKES</a></p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($challenge && $challenge['identity_hint']): ?>
                <p>Permintaan diterima dari <?= e($challenge['identity_hint']) ?>. Konfirmasi hanya jika Anda mengenali pengirim.</p>
                <?php postButton(WA_SETTINGS . '/confirm','Konfirmasi penautan',['id'=>$challenge['id']]); ?>
            <?php elseif ($challenge): ?>
                <p>Menunggu pesan penautan. Muat ulang halaman setelah mengirim kode.</p>
            <?php endif; ?>
            <?php if (waWebhookReady()): postButton(WA_SETTINGS . '/code','Buat kode sekali pakai'); endif; ?>
        </section>
        <section class="surface">
            <h2>Pemberitahuan peta</h2>
            <p>Kategori, bisu, dan jam tenang juga berlaku di peta. Balas STOP di WhatsApp untuk menghentikan pemberitahuan.</p>
            <p>Template kejadian <?= e(waTemplateReady() ? 'dikonfigurasi' : 'belum dikonfigurasi') ?>.</p>
            <?php postStart(WA_SETTINGS . '/preferences'); ?>
            <div class="form-grid">
                <?php selectField('opt_in','Pemberitahuan WhatsApp',[0=>'Nonaktif',1=>'Aktif (opt-in)'],(int) ($valid && $link['opted_in_at'])); ?>
                <?php selectField('muted','Bisu',[0=>'Tidak',1=>'Ya'],(int) $pref['muted']); ?>
                <?php foreach (MAP_CATEGORIES as $key=>$label):
                    selectField($key,$label,[0=>'Nonaktif',1=>'Aktif'],(int) $pref[$key]);
                endforeach; ?>
                <?php input('quiet_start','Mulai jam tenang',$pref['quiet_start'],'time'); ?>
                <?php input('quiet_end','Akhir jam tenang',$pref['quiet_end'],'time'); ?>
            </div>
            <small>Zona waktu <?= e($pref['zone']) ?></small><br>
            <button>Simpan preferensi</button></form>
        </section>
        <section class="surface">
            <h2>Riwayat pengiriman</h2>
            <?php table(['Jenis','Status','Waktu antre (UTC)'],$history,static function (array $row): void {
                $label = ['queued'=>'Antre','sending'=>'Sedang diproses','accepted'=>'Diterima provider',
                    'sent'=>'Dikirim provider','delivered'=>'Tiba di perangkat','read'=>'Dibaca','failed'=>'Gagal',
                    'unknown'=>'Hasil belum dapat dipastikan','skipped'=>'Dilewati'];
                td($row['kind'] === 'map_event' ? 'Peta' : 'Jawaban chatbot',$label[$row['state']],$row['created_at']);
            }); ?>
        </section>
        <?php
    },$user,WA_SETTINGS);
}

function whatsappCode(array $user): never
{
    if (!waWebhookReady() || !waMigrated()) {
        fail(503,'Webhook WhatsApp belum siap.');
    }
    $_SESSION['whatsapp_code'] = waCreateChallenge($user['id']);
    redirect(WA_SETTINGS);
}

function whatsappConfirm(array $user): never
{
    waConfirmChallenge($user['id'],key32($_POST));
    done('Akun WhatsApp tertaut. Pemberitahuan peta tetap nonaktif sampai Anda memilih opt-in.',WA_SETTINGS);
}

function whatsappUnlink(array $user): never
{
    waUnlink($user['id']);
    unset($_SESSION['whatsapp_code']);
    done('Identitas WhatsApp diputuskan dan pesan yang masih mengantre dibatalkan.',WA_SETTINGS);
}

function whatsappPreferences(array $user): never
{
    $opt = choice($_POST,'opt_in',['0','1'],'0')==='1';
    tx(static function () use ($user,$opt): void {
        $existing = waLinkForUser($user['id']);
        if ($opt && (!$existing || !waActor($existing) || !can($user,'map'))) {
            fail(409,'Tautkan akun yang memiliki akses peta sebelum mengaktifkan pemberitahuan.');
        }
        storeMapPreferences($user['id'],$_POST);
        if (!$existing) {
            return;
        }
        qu('UPDATE whatsapp_links SET opted_in_at=IF(?,COALESCE(opted_in_at,UTC_TIMESTAMP()),NULL)
            WHERE id=? AND active_slot=1',[(int) $opt,$existing['id']]);
        if (!$opt) {
            qu("UPDATE whatsapp_outbox SET state='skipped',error_kind='opted_out',payload_ciphertext=NULL
                WHERE link_id=? AND kind='map_event' AND state='queued'",[$existing['id']]);
        }
        audit($user['id'],$opt ? 'whatsapp_opt_in' : 'whatsapp_opt_out','whatsapp');
    });
    done('Preferensi disimpan.',WA_SETTINGS);
}
