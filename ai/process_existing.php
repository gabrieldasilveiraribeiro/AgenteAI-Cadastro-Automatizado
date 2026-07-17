<?php
session_start();
require_once __DIR__ . '/../conexao/conecta.php';
require_once __DIR__ . '/../ai/config.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $productIds = $_POST['product_ids'] ?? [];
    if (!$productIds || !is_array($productIds)) throw new Exception("Nenhum produto");

    $pdo = $conexao;
    $pdo->beginTransaction();
    $created_by = (int)($_SESSION['idLogado'] ?? 0);
    $id_dono = $created_by;

    // cria job
    $stmt = $pdo->prepare("INSERT INTO ai_jobs (created_by, id_dono, status, created_at) VALUES (?, ?, 'queued', NOW())");
    $stmt->execute([$created_by, $id_dono]);
    $jobId = (int)$pdo->lastInsertId();

    $original_dir = __DIR__ . "/../uploads/ai_jobs/{$jobId}/original";
    if (!mkdir($original_dir, 0777, true) && !is_dir($original_dir)) throw new Exception("Falha ao criar diretório: {$original_dir}");

    $selectProd = $pdo->prepare("SELECT id, nome_item, imagem FROM leilao_itens WHERE id = ? LIMIT 1");
    foreach ($productIds as $pid) {
        $pid = (int)$pid;
        $selectProd->execute([$pid]);
        $row = $selectProd->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;
        $srcImg = $row['imagem'] ?? '';
        $fullSrc = null;
        if ($srcImg && (strpos($srcImg, '/') === 0 || preg_match('#^https?://#i', $srcImg))) {
            $fullSrc = $_SERVER['DOCUMENT_ROOT'] . $srcImg;
            if (!file_exists($fullSrc)) $fullSrc = __DIR__ . '/../uploads/prod_images/' . ltrim($srcImg, '/');
        } else {
            $fullSrc = __DIR__ . '/../uploads/prod_images/' . ltrim($srcImg, '/');
        }

        $target = null;
        if ($fullSrc && file_exists($fullSrc) && filesize($fullSrc) > 0) {
            $filename = uniqid("prod_{$pid}_") . '.' . pathinfo($fullSrc, PATHINFO_EXTENSION);
            $target = "{$original_dir}/{$filename}";
            @copy($fullSrc, $target);
        }

        $hash = null;
        if ($target && file_exists($target)) {
            $h = @hash_file('sha256', $target);
            if ($h) $hash = $h;
        }

        $ins = $pdo->prepare("INSERT INTO ai_job_images (job_id, src_path, hash_sha256, source_product_id) VALUES (?, ?, ?, ?)");
        try { $ins->execute([$jobId, $target, $hash, $pid]); }
        catch(PDOException $ex) {
            if ($ex->getCode() == 23000) {
                // duplicate -> log and continue
                error_log("process_existing duplicate hash for product {$pid} / file {$target}");
            } else { throw $ex; }
        }
    }

    $pdo->prepare("UPDATE ai_jobs SET total_images=? WHERE id=?")->execute([count($productIds), $jobId]);
    $pdo->commit();

    // dispare Python (fire-and-forget): você já usa 127.0.0.1:5001/process_job
    $ch = curl_init('http://127.0.0.1:5001/process_job');
    curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>['job_id'=>$jobId], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8]);
    @curl_exec($ch); @curl_close($ch);

    echo json_encode(['ok'=>true, 'job_id'=>$jobId], JSON_UNESCAPED_UNICODE);
} catch(Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("process_existing error: ".$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
