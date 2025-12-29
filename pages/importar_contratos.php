<?php
// IMPORTADOR DE CONTRATOS V3 - COM DIAGNÓSTICO DE ERROS
// Ordem: FORNECEDOR | RESPONSÁVEL | VALOR | DATA
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($pdo)) {
    $db_file = __DIR__ . '/../includes/db.php';
    if (file_exists($db_file)) include $db_file;
}

$msg = "";
$log_erros = []; // Para mostrar na tela o que aconteceu

// --- FUNÇÕES ---
function limparValor($valor) {
    if (!$valor) return 0;
    // Remove R$, espaços e caracteres invisíveis
    $valor = preg_replace('/[R$\s]/u', '', $valor);
    // Lógica para 1.000,00 -> 1000.00
    if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (strpos($valor, ',') !== false) {
        $valor = str_replace(',', '.', $valor);
    }
    return (float)$valor;
}

function limparData($data) {
    if (!$data) return date('Y-m-d');
    $data = trim($data);
    if (is_numeric($data) && $data > 20000) return date('Y-m-d', ($data - 25569) * 86400);
    $data = str_replace('-', '/', $data);
    if (strpos($data, '/') !== false) {
        $p = explode('/', $data);
        if (count($p) == 3) return "{$p[2]}-{$p[1]}-{$p[0]}"; // d/m/Y -> Y-m-d
    }
    return $data;
}

// --- PROCESSAMENTO ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['dados_excel'])) {
    
    // 1. Carrega Fornecedores
    $stmtMap = $pdo->query("SELECT id, nome FROM fornecedores");
    $mapaFornecedores = [];
    while ($row = $stmtMap->fetch(PDO::FETCH_ASSOC)) {
        $mapaFornecedores[mb_strtoupper(trim($row['nome']), 'UTF-8')] = $row['id'];
    }

    // 2. Quebra as linhas de forma segura (Windows/Mac/Linux)
    $raw_text = $_POST['dados_excel'];
    $linhas = preg_split('/\\r\\n|\\r|\\n/', $raw_text);
    
    $sucessos = 0;
    $criados = 0;

    $stmtCon = $pdo->prepare("INSERT INTO contratos (fornecedor_id, responsavel, valor, data_contrato, descricao) VALUES (?, ?, ?, ?, ?)");
    $stmtForn = $pdo->prepare("INSERT INTO fornecedores (nome) VALUES (?)");

    foreach ($linhas as $index => $linha) {
        $linha = trim($linha);
        if (empty($linha)) continue;

        // TENTA DESCOBRIR O SEPARADOR (TAB ou ;)
        if (strpos($linha, "\t") !== false) {
            $cols = explode("\t", $linha);
        } elseif (strpos($linha, ";") !== false) {
            $cols = explode(";", $linha);
        } else {
            // Se não tem tab nem ponto e virgula, tenta espaço duplo (gambiarra)
            $cols = preg_split('/\s{2,}/', $linha);
        }

        // DIAGNÓSTICO: Se tiver menos de 2 colunas, avisa o erro
        if (count($cols) < 2) {
            $log_erros[] = "Linha " . ($index+1) . ": Ignorada (Só encontrei 1 coluna: '$linha'). Tente copiar do Excel novamente.";
            continue;
        }

        // DADOS
        $nome_raw = trim($cols[0] ?? '');
        $nome_key = mb_strtoupper($nome_raw, 'UTF-8');
        $resp     = trim($cols[1] ?? 'Setor Comercial');
        $valor    = limparValor($cols[2] ?? 0);
        $data     = limparData($cols[3] ?? null);

        // Pula Cabeçalho
        if (in_array($nome_key, ['FORNECEDOR', 'NOME', 'EMPRESA'])) {
            $log_erros[] = "Linha " . ($index+1) . ": Cabeçalho ignorado.";
            continue;
        }

        // LÓGICA DE CADASTRO
        $id_forn = $mapaFornecedores[$nome_key] ?? null;

        if (!$id_forn) {
            try {
                $stmtForn->execute([$nome_raw]);
                $id_forn = $pdo->lastInsertId();
                $mapaFornecedores[$nome_key] = $id_forn;
                $criados++;
                $log_erros[] = "Linha " . ($index+1) . ": Novo fornecedor criado ($nome_raw).";
            } catch (Exception $e) {
                $log_erros[] = "Linha " . ($index+1) . ": Erro ao criar fornecedor ($nome_raw).";
                continue;
            }
        }

        if ($id_forn) {
            $stmtCon->execute([$id_forn, $resp, $valor, $data, "Importado via Excel"]);
            $sucessos++;
        }
    }

    // MONTA O ALERTA
    $classe = ($sucessos > 0) ? 'success' : 'warning';
    $msg = "<div class='alert alert-$classe shadow-sm'>
                <h5 class='fw-bold'><i class='bi bi-activity'></i> Relatório da Importação</h5>
                <ul>
                    <li>Contratos Importados: <b>$sucessos</b></li>
                    <li>Fornecedores Criados: <b>$criados</b></li>
                </ul>
            </div>";
            
    // SE NÃO IMPORTOU NADA, MOSTRA O LOG
    if ($sucessos == 0 && count($log_erros) > 0) {
        $msg .= "<div class='alert alert-danger'>
                    <h6><i class='bi bi-bug'></i> O que deu errado (Diagnóstico):</h6>
                    <ul class='small mb-0' style='max-height: 200px; overflow-y: auto;'>";
        foreach ($log_erros as $err) {
            $msg .= "<li>$err</li>";
        }
        $msg .= "</ul></div>";
    }
}
?>

<div class="container p-4">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold text-dark"><i class="bi bi-magic text-primary me-2"></i>Importador V3 (Diagnóstico)</h3>
                <a href="index.php?page=gerenciador_global" class="btn btn-outline-secondary btn-sm">Voltar</a>
            </div>

            <?php echo $msg; ?>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white fw-bold py-3">
                    <i class="bi bi-clipboard-check me-2"></i> Área de Colagem
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-light border mb-3 small">
                        <strong>Dica para funcionar:</strong><br>
                        1. Selecione as células no Excel (não clique duas vezes na célula, apenas selecione).<br>
                        2. Dê Ctrl+C.<br>
                        3. Cole abaixo. O sistema espera que exista um espaço grande (TAB) entre o nome e o responsável.
                    </div>
                    
                    <form method="POST">
                        <textarea name="dados_excel" class="form-control font-monospace small mb-3" rows="10" placeholder="Cole aqui seus dados..." style="background:#f8f9fa; white-space: pre;"></textarea>
                        <button type="submit" class="btn btn-primary w-100 fw-bold">PROCESSAR E ANALISAR</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>