<?php
// actions/excluir_parcela.php
include '../conexao.php'; 

$id = $_GET['id'] ?? 0;
$cliente_id = $_GET['cli'] ?? 0;

if ($id) {
    try {
        // 1. Descobre qual é a Venda ID antes de apagar a parcela
        $stmtGet = $pdo->prepare("SELECT venda_id FROM parcelas_imob WHERE id = ?");
        $stmtGet->execute([$id]);
        $venda_id = $stmtGet->fetchColumn();

        // 2. Deleta a parcela
        $stmt = $pdo->prepare("DELETE FROM parcelas_imob WHERE id = ?");
        $stmt->execute([$id]);
        
        // --- NOVO: RECALCULA O TOTAL APÓS EXCLUSÃO ---
        if ($venda_id) {
            $stmtSum = $pdo->prepare("SELECT SUM(valor_parcela) FROM parcelas_imob WHERE venda_id = ?");
            $stmtSum->execute([$venda_id]);
            $novoTotal = $stmtSum->fetchColumn() ?: 0; // Se não sobrar nada, vira 0

            $stmtUpd = $pdo->prepare("UPDATE vendas_imob SET valor_total = ? WHERE id = ?");
            $stmtUpd->execute([$novoTotal, $venda_id]);
        }
        // ---------------------------------------------

        header("Location: ../index.php?page=detalhe_cliente&id=$cliente_id&msg=deletado");
    } catch (PDOException $e) {
        die("Erro ao excluir: " . $e->getMessage());
    }
} else {
    header("Location: ../index.php?page=clientes");
}
?>