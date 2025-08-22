<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

// Evita HTML de warnings/notices na resposta
ob_start();
ini_set('display_errors', '0');

header("Content-Type: application/json; charset=utf-8");
require_once "conectadb.php";

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Método inválido.');
    }

    $idfuncionario = isset($_POST['idfuncionario']) ? (int)$_POST['idfuncionario'] : 0;
    $data          = $_POST['data']   ?? '';
    $duracaoMin    = isset($_POST['duracao']) ? (int)$_POST['duracao'] : 30; // minutos
    $catId         = isset($_POST['cat_id'])  ? (int)$_POST['cat_id']  : 0;

    if ($idfuncionario <= 0 || !$data) {
        throw new InvalidArgumentException('Parâmetros obrigatórios ausentes.');
    }

    // Prioriza duração do catálogo, se informada
    if ($catId > 0) {
        $sqlCat = "SELECT CAT_TEMPO FROM catalogo WHERE CAT_ID = ?";
        if ($stmtCat = $link->prepare($sqlCat)) {
            $stmtCat->bind_param("i", $catId);
            $stmtCat->execute();
            $resCat = $stmtCat->get_result();
            if ($rowCat = $resCat->fetch_assoc()) {
                $duracaoMin = max(1, (int)$rowCat['CAT_TEMPO']);
            }
            $stmtCat->close();
        }
    }
    $duracaoMin = max(1, $duracaoMin);

    // Configuração de slots
    $passo = 30; // minutos
    $slotsNecessarios = (int)ceil($duracaoMin / $passo);

    // Janela de funcionamento
    $aberturaMin   = 8 * 60;   // 08:00
    $fechamentoMin = 21 * 60;  // 21:00

    // Se a data selecionada é hoje, ignora horários passados
    $hoje = (new DateTime('today'))->format('Y-m-d');
    $primeiroMinPermitido = $aberturaMin;
    if ($data === $hoje) {
        $agora = new DateTime();
        $minutosAgora = ((int)$agora->format('H')) * 60 + (int)$agora->format('i');
        $primeiroMinPermitido = max($aberturaMin, (int)(ceil($minutosAgora / $passo) * $passo));
    }

    // Carrega agendamentos EXISTENTES do funcionário, na data, status=1 (ocupado)
    $sql = "
        SELECT a.AG_HORA, c.CAT_TEMPO
        FROM agenda a
        INNER JOIN catalogo c ON a.FK_CAT_ID = c.CAT_ID
        WHERE a.FK_FUN_ID = ?
          AND a.AG_DATA   = ?
          AND a.AG_STATUS = 1
    ";
    $stmt = $link->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Falha ao preparar consulta de agenda.");
    }
    $stmt->bind_param("is", $idfuncionario, $data);
    $stmt->execute();
    $result = $stmt->get_result();

    // Marca blocos ocupados de 30 em 30
    $ocupados = []; // [minutoInicioBloco => true]
    while ($row = $result->fetch_assoc()) {
        $horaStr = $row['AG_HORA'];      // "HH:MM:SS"
        $durMin  = (int)$row['CAT_TEMPO']; // minutos (padrão do cadastro do serviço)
        $durMin  = max(1, $durMin);

        $partes = explode(':', $horaStr);
        $inicioMin = ((int)$partes[0]) * 60 + (int)$partes[1];

        $bloquinhos = (int)ceil($durMin / $passo);
        for ($k = 0; $k < $bloquinhos; $k++) {
            $ocupados[$inicioMin + $k * $passo] = true;
        }
    }
    $stmt->close();

    // Gera horários candidatos (inícios) que ainda caibam
    $ultimoInicio = $fechamentoMin - $slotsNecessarios * $passo;
    $livres = [];

    for ($ini = $aberturaMin; $ini <= $ultimoInicio; $ini += $passo) {
        if ($ini < $primeiroMinPermitido) continue;

        $ok = true;
        for ($k = 0; $k < $slotsNecessarios; $k++) {
            $bloco = $ini + $k * $passo;
            if (isset($ocupados[$bloco])) {
                $ok = false;
                break;
            }
        }
        if (!$ok) continue;

        $h = (int)floor($ini / 60);
        $m = (int)($ini % 60);
        $livres[] = sprintf("%02d:%02d:00", $h, $m);
    }

    sort($livres);

    // --- SAI SOMENTE JSON (sem HTML, sem <br/>) ---
    // Se quiser forçar sempre objeto, descomente abaixo:
    // echo json_encode(["ok" => true, "horarios" => $livres]);
    // exit;

    // Mantém compatível com o frontend antigo: retorna ARRAY puro
    // e ainda assim captura vazamentos:
    $leak = trim(ob_get_clean() ?: '');
    if ($leak !== '') {
        // Se houve vazamento, devolve objeto com debug (frontend já trata)
        echo json_encode(["ok" => true, "horarios" => $livres, "debug" => $leak]);
    } else {
        echo json_encode($livres);
    }
    exit;

} catch (Throwable $e) {
    $leak = trim(ob_get_clean() ?: '');
    $payload = [
        "ok"    => false,
        "error" => $e->getMessage()
    ];
    if ($leak !== '') $payload["debug"] = $leak;

    echo json_encode($payload);
    exit;
}
