<?php
// actions/salvar_venda.php
include '../conexao.php'; 

function converterMoeda($valor) {
    if (empty($valor)) return 0;
    $valor = str_replace(['R$', ' ', '.'], '', $valor);
    return str_replace(',', '.', $valor);
}

$id           = $_POST['id'] ?? '';
$cliente_id   = $_POST['cliente_id'];
$codigo       = $_POST['codigo_compra'];
$nome_casa    = $_POST['nome_casa'];
$nome_empresa = $_POST['nome_empresa'];
$valor_total  = converterMoeda($_POST['valor_total']);

// Datas (se vier vazio, salva NULL)
$dt_contrato = !empty($_POST['data_contrato']) ? $_POST['data_contrato'] : NULL;
$dt_inicio   = !empty($_POST['data_inicio']) ? $_POST['data_inicio'] : NULL;
$dt_fim      = !empty($_POST['data_fim']) ? $_POST['data_fim'] : NULL;

try {
    if (!empty($id)) {
        // EDITAR
        $sql = "UPDATE vendas_imob SET 
                codigo_compra = ?, nome_casa = ?, nome_empresa = ?, 
                data_contrato = ?, data_inicio = ?, data_fim = ?, valor_total = ? 
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$codigo, $nome_casa, $nome_empresa, $dt_contrato, $dt_inicio, $dt_fim, $valor_total, $id]);
    } else {
        // INSERIR
        $sql = "INSERT INTO vendas_imob 
                (cliente_id, codigo_compra, nome_casa, nome_empresa, data_contrato, data_inicio, data_fim, valor_total) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cliente_id, $codigo, $nome_casa, $nome_empresa, $dt_contrato, $dt_inicio, $dt_fim, $valor_total]);
    }

    header("Location: ../index.php?page=detalhe_cliente&id=$cliente_id&msg=venda_salva");
} catch (PDOException $e) {
    die("Erro ao salvar contrato: " . $e->getMessage());
}
?>