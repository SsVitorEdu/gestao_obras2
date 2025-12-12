<?php
// actions/excluir_venda.php
include '../conexao.php'; 

$id = $_GET['id'] ?? 0;
$cliente_id = $_GET['cli'] ?? 0;

if ($id) {
    try {
        $pdo->beginTransaction();

        // 1. Apaga as parcelas vinculadas a essa venda
        $stmt1 = $pdo->prepare("DELETE FROM parcelas_imob WHERE venda_id = ?");
        $stmt1->execute([$id]);

        // 2. Apaga a venda/contrato
        $stmt2 = $pdo->prepare("DELETE FROM vendas_imob WHERE id = ?");
        $stmt2->execute([$id]);

        $pdo->commit();
        
        header("Location: ../index.php?page=detalhe_cliente&id=$cliente_id&msg=venda_excluida");
    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Erro ao excluir contrato: " . $e->getMessage());
    }
} else {
    header("Location: ../index.php?page=clientes");
}
?>