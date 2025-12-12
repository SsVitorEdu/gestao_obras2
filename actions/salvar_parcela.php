<?php
// actions/salvar_parcela.php
include '../conexao.php'; 

function converterMoeda($valor) {
    if (empty($valor)) return 0;
    $valor = str_replace(['R$', ' ', '.'], '', $valor);
    return str_replace(',', '.', $valor);
}

// Captura dados
$id           = $_POST['id'] ?? '';
$venda_id     = $_POST['venda_id'];
$cliente_id   = $_POST['cliente_id'];
$numero       = $_POST['numero_parcela'];
$vencimento   = $_POST['data_vencimento'];
$valor_parcela = converterMoeda($_POST['valor_parcela']);

// Campos de pagamento
$data_pagamento = !empty($_POST['data_pagamento']) ? $_POST['data_pagamento'] : NULL;
$valor_pago     = converterMoeda($_POST['valor_pago']);

try {
    // 1. SALVA A PARCELA (Lógica original)
    if (!empty($id)) {
        $sql = "UPDATE parcelas_imob SET 
                numero_parcela = ?, data_vencimento = ?, valor_parcela = ?, 
                data_pagamento = ?, valor_pago = ? 
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$numero, $vencimento, $valor_parcela, $data_pagamento, $valor_pago, $id]);
    } else {
        $sql = "INSERT INTO parcelas_imob 
                (venda_id, numero_parcela, data_vencimento, valor_parcela, data_pagamento, valor_pago) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$venda_id, $numero, $vencimento, $valor_parcela, $data_pagamento, $valor_pago]);
    }

    // --- NOVO: RECALCULA O TOTAL DO CONTRATO AUTOMATICAMENTE ---
    // Somamos todas as parcelas dessa venda (incluindo a que acabamos de salvar)
    $stmtSum = $pdo->prepare("SELECT SUM(valor_parcela) FROM parcelas_imob WHERE venda_id = ?");
    $stmtSum->execute([$venda_id]);
    $novoTotal = $stmtSum->fetchColumn(); // Pega o valor da soma

    // Atualizamos a tabela principal de vendas com a nova soma
    $stmtUpd = $pdo->prepare("UPDATE vendas_imob SET valor_total = ? WHERE id = ?");
    $stmtUpd->execute([$novoTotal, $venda_id]);
    // -----------------------------------------------------------

    header("Location: ../index.php?page=detalhe_cliente&id=$cliente_id&msg=sucesso");

} catch (PDOException $e) {
    die("Erro ao salvar parcela: " . $e->getMessage());
}
?>