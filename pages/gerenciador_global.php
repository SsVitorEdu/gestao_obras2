<?php
// GERENCIADOR GLOBAL V4 - OTIMIZADO (PADRÃO MÊS ATUAL) + CAMPOS ATIVOS
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

// --- 1. CONEXÃO E PROCESSAMENTO ---
if (!isset($pdo)) {
    $db_file = __DIR__ . '/../includes/db.php';
    if (file_exists($db_file)) include $db_file;
}

$msg = "";

// LÓGICA DE SALVAR (UPDATE)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    try {
        if ($_POST['acao'] == 'editar_pedido') {
            $sql = "UPDATE pedidos SET 
                    data_pedido = ?, numero_of = ?, valor_bruto_pedido = ?, 
                    valor_total_rec = ?, forma_pagamento = ?, observacao = ?,
                    obra_id = ?, fornecedor_id = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['data_pedido'], $_POST['numero_of'], $_POST['valor'], 
                $_POST['valor_rec'], $_POST['pagamento'], $_POST['obs'], 
                $_POST['obra_id'], $_POST['fornecedor_id'],
                $_POST['id']
            ]);
            $msg = "<div class='alert alert-success shadow-sm'>✅ Pedido atualizado com sucesso!</div>";
        }
        elseif ($_POST['acao'] == 'editar_contrato') {
            // AGORA COM TODOS OS CAMPOS (JÁ QUE O BANCO FOI CORRIGIDO)
            $sql = "UPDATE contratos SET 
                    data_contrato = ?, numero_contrato = ?, valor = ?, 
                    descricao = ?, responsavel = ?, fornecedor_id = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['data_contrato'], $_POST['numero_contrato'], $_POST['valor'], 
                $_POST['descricao'], $_POST['responsavel'], $_POST['fornecedor_id'], 
                $_POST['id']
            ]);
            $msg = "<div class='alert alert-success shadow-sm'>✅ Contrato atualizado com sucesso!</div>";
        }
        elseif ($_POST['acao'] == 'excluir_pedido') {
            $pdo->prepare("DELETE FROM pedidos WHERE id = ?")->execute([$_POST['id']]);
            $msg = "<div class='alert alert-warning shadow-sm'>🗑️ Pedido excluído.</div>";
        }
        elseif ($_POST['acao'] == 'excluir_contrato') {
            $pdo->prepare("DELETE FROM contratos WHERE id = ?")->execute([$_POST['id']]);
            $msg = "<div class='alert alert-warning shadow-sm'>🗑️ Contrato excluído.</div>";
        }
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>Erro: " . $e->getMessage() . "</div>";
    }
}

// --- 2. FILTROS OTIMIZADOS ---
// PADRÃO: Pega apenas o MÊS ATUAL (Do dia 01 até o último dia 't')
$dt_ini = $_GET['dt_ini'] ?? date('Y-m-01'); 
$dt_fim = $_GET['dt_fim'] ?? date('Y-m-t');

$f_forn = $_GET['f_forn'] ?? '';
$f_obra = $_GET['f_obra'] ?? '';
$f_pag  = $_GET['f_pag'] ?? '';
$f_of   = $_GET['f_of'] ?? '';

// LISTAS DE APOIO
$lista_obras = $pdo->query("SELECT id, nome FROM obras ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$lista_forns = $pdo->query("SELECT id, nome FROM fornecedores ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$lista_pags  = $pdo->query("SELECT DISTINCT forma_pagamento FROM pedidos WHERE forma_pagamento != '' ORDER BY forma_pagamento")->fetchAll(PDO::FETCH_ASSOC);

// QUERY PEDIDOS
$sql_ped = "SELECT p.*, o.nome as obra_nome, f.nome as forn_nome 
            FROM pedidos p 
            LEFT JOIN obras o ON p.obra_id = o.id 
            LEFT JOIN fornecedores f ON p.fornecedor_id = f.id
            WHERE p.data_pedido BETWEEN ? AND ?";
$params_ped = [$dt_ini, $dt_fim];

if($f_forn) { $sql_ped .= " AND p.fornecedor_id = ?"; $params_ped[] = $f_forn; }
if($f_obra) { $sql_ped .= " AND p.obra_id = ?"; $params_ped[] = $f_obra; }
if($f_pag)  { $sql_ped .= " AND p.forma_pagamento = ?"; $params_ped[] = $f_pag; }
if($f_of)   { $sql_ped .= " AND p.numero_of LIKE ?"; $params_ped[] = "%$f_of%"; }

// Adicionado ORDER BY para organizar a lista
$sql_ped .= " ORDER BY p.data_pedido DESC";

$pedidos = $pdo->prepare($sql_ped);
$pedidos->execute($params_ped);

// QUERY CONTRATOS
$sql_con = "SELECT c.*, f.nome as forn_nome 
            FROM contratos c 
            LEFT JOIN fornecedores f ON c.fornecedor_id = f.id 
            WHERE c.data_contrato BETWEEN ? AND ?";
$params_con = [$dt_ini, $dt_fim];

if($f_forn) { $sql_con .= " AND c.fornecedor_id = ?"; $params_con[] = $f_forn; }

$sql_con .= " ORDER BY c.data_contrato DESC";

$contratos = $pdo->prepare($sql_con);
$contratos->execute($params_con);
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
    
    .card-filter { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .table-container { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
    
    .btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; }
    
    /* DataTables Custom */
    .dataTables_wrapper .dataTables_length select { border-radius: 6px; padding: 4px; }
    .dataTables_wrapper .dataTables_filter input { border-radius: 6px; padding: 6px; border: 1px solid #ddd; }
    th { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; background-color: #f8f9fa !important; }
    td { font-size: 0.9rem; vertical-align: middle; }
    
    .nav-pills .nav-link.active { background-color: #0d6efd; font-weight: bold; }
    .nav-pills .nav-link { color: #555; }
</style>

<div class="container-fluid p-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Gerenciador Global</h3>
            <span class="badge bg-white text-muted border px-3 py-2 rounded-pill">Central de Edição</span>
        </div>
        <div>
            <a href="index.php?page=importar_contratos" class="btn btn-warning btn-sm fw-bold shadow-sm">
                <i class="bi bi-file-earmark-spreadsheet"></i> Importar Contratos
            </a>
        </div>
    </div>

    <?= $msg ?>

    <div class="card card-filter mb-4">
        <div class="card-body p-4">
            <form method="GET">
                <input type="hidden" name="page" value="gerenciador_global">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">Período (Mês Atual)</label>
                        <div class="input-group input-group-sm">
                            <input type="date" name="dt_ini" class="form-control" value="<?= $dt_ini ?>">
                            <span class="input-group-text">a</span>
                            <input type="date" name="dt_fim" class="form-control" value="<?= $dt_fim ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">Fornecedor</label>
                        <select name="f_forn" class="form-select form-select-sm select2">
                            <option value="">Todas</option>
                            <?php foreach($lista_forns as $f): ?>
                                <option value="<?= $f['id'] ?>" <?= $f_forn == $f['id'] ? 'selected' : '' ?>><?= $f['nome'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted">Obra</label>
                        <select name="f_obra" class="form-select form-select-sm select2">
                            <option value="">Todas</option>
                            <?php foreach($lista_obras as $o): ?>
                                <option value="<?= $o['id'] ?>" <?= $f_obra == $o['id'] ? 'selected' : '' ?>><?= $o['nome'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted">Pagamento</label>
                        <select name="f_pag" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <?php foreach($lista_pags as $p): ?>
                                <option value="<?= $p['forma_pagamento'] ?>" <?= $f_pag == $p['forma_pagamento'] ? 'selected' : '' ?>><?= $p['forma_pagamento'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-muted">Buscar OF</label>
                        <input type="text" name="f_of" class="form-control form-control-sm" placeholder="Nº OF..." value="<?= $f_of ?>">
                    </div>
                    <div class="col-12 text-end">
                        <a href="index.php?page=gerenciador_global" class="btn btn-outline-secondary btn-sm">Limpar</a>
                        <button type="submit" class="btn btn-primary btn-sm fw-bold px-4"><i class="bi bi-search"></i> Filtrar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="pills-pedidos-tab" data-bs-toggle="pill" data-bs-target="#pills-pedidos" type="button"><i class="bi bi-cart me-2"></i>Pedidos / OFs</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="pills-contratos-tab" data-bs-toggle="pill" data-bs-target="#pills-contratos" type="button"><i class="bi bi-file-text me-2"></i>Contratos</button>
        </li>
    </ul>

    <div class="tab-content" id="pills-tabContent">
        
        <div class="tab-pane fade show active" id="pills-pedidos">
            <div class="table-container">
                <table id="tablePedidos" class="table table-hover table-striped w-100" style="width:100%">
                    <thead>
                        <tr>
                            <th>Ações</th>
                            <th>Data</th>
                            <th>Nº OF</th>
                            <th>Obra</th>
                            <th>Fornecedor</th>
                            <th>Valor Bruto</th>
                            <th>Valor Pago</th>
                            <th>Saldo</th>
                            <th>Pagamento</th>
                            <th>Obs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $pedidos->fetch(PDO::FETCH_ASSOC)): 
                            $saldo = $row['valor_bruto_pedido'] - $row['valor_total_rec'];
                        ?>
                        <tr>
                            <td style="white-space: nowrap;">
                                <button class="btn btn-primary btn-sm btn-icon" onclick='editarPedido(<?= json_encode($row) ?>)' title="Editar"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-outline-danger btn-sm btn-icon" onclick="excluirItem(<?= $row['id'] ?>, 'pedido')" title="Excluir"><i class="bi bi-trash"></i></button>
                            </td>
                            <td><?= date('d/m/Y', strtotime($row['data_pedido'])) ?></td>
                            <td class="fw-bold text-primary"><?= $row['numero_of'] ?></td>
                            <td><?= $row['obra_nome'] ?></td>
                            <td><?= $row['forn_nome'] ?></td>
                            <td>R$ <?= number_format($row['valor_bruto_pedido'], 2, ',', '.') ?></td>
                            <td class="text-success">R$ <?= number_format($row['valor_total_rec'], 2, ',', '.') ?></td>
                            <td class="fw-bold <?= $saldo > 0 ? 'text-danger' : 'text-muted' ?>">R$ <?= number_format($saldo, 2, ',', '.') ?></td>
                            <td><span class="badge bg-light text-dark border"><?= $row['forma_pagamento'] ?></span></td>
                            <td class="small text-muted text-truncate" style="max-width: 150px;"><?= $row['observacao'] ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="pills-contratos">
            <div class="table-container">
                <table id="tableContratos" class="table table-hover table-striped w-100">
                    <thead>
                        <tr>
                            <th>Ações</th>
                            <th>Data</th>
                            <th>Nº Contrato</th>
                            <th>Fornecedor</th>
                            <th>Valor Global</th>
                            <th>Responsável</th>
                            <th>Descrição</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($c = $contratos->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td style="white-space: nowrap;">
                                <button class="btn btn-warning btn-sm btn-icon text-white" onclick='editarContrato(<?= json_encode($c) ?>)' title="Editar"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-outline-danger btn-sm btn-icon" onclick="excluirItem(<?= $c['id'] ?>, 'contrato')" title="Excluir"><i class="bi bi-trash"></i></button>
                            </td>
                            <td><?= date('d/m/Y', strtotime($c['data_contrato'])) ?></td>
                            <td class="fw-bold"><?= $c['numero_contrato'] ?></td>
                            <td><?= $c['forn_nome'] ?></td>
                            <td class="fw-bold text-success">R$ <?= number_format($c['valor'], 2, ',', '.') ?></td>
                            <td><?= $c['responsavel'] ?></td>
                            <td class="small text-muted text-truncate" style="max-width: 200px;"><?= $c['descricao'] ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalPedido" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Editar Pedido / OF</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="acao" value="editar_pedido">
                    <input type="hidden" name="id" id="ped_id">
                    
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold">Nº OF</label>
                            <input type="text" name="numero_of" id="ped_of" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Data</label>
                            <input type="date" name="data_pedido" id="ped_data" class="form-control" required>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label small fw-bold">Obra</label>
                            <select name="obra_id" id="ped_obra" class="form-select select2-modal">
                                <?php foreach($lista_obras as $o) echo "<option value='".$o['id']."'>".$o['nome']."</option>"; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Fornecedor</label>
                            <select name="fornecedor_id" id="ped_forn" class="form-select select2-modal">
                                <?php foreach($lista_forns as $f) echo "<option value='".$f['id']."'>".$f['nome']."</option>"; ?>
                            </select>
                        </div>

                        <div class="col-6">
                            <label class="form-label small fw-bold">Valor Bruto (R$)</label>
                            <input type="number" step="0.01" name="valor" id="ped_valor" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Valor Pago (R$)</label>
                            <input type="number" step="0.01" name="valor_rec" id="ped_rec" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Forma de Pagamento</label>
                            <input type="text" name="pagamento" id="ped_pag" class="form-control" list="list_pags">
                            <datalist id="list_pags">
                                <?php foreach($lista_pags as $p) echo "<option value='".$p['forma_pagamento']."'>"; ?>
                            </datalist>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Observação</label>
                            <textarea name="obs" id="ped_obs" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold">Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalContrato" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold">Editar Contrato</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="acao" value="editar_contrato">
                    <input type="hidden" name="id" id="con_id">
                    
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold">Fornecedor</label>
                            <select name="fornecedor_id" id="con_forn" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach($lista_forns as $f): ?>
                                    <option value="<?= $f['id'] ?>"><?= $f['nome'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Nº Contrato</label>
                            <input type="text" name="numero_contrato" id="con_num" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Data</label>
                            <input type="date" name="data_contrato" id="con_data" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Valor Global (R$)</label>
                            <input type="number" step="0.01" name="valor" id="con_valor" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Responsável</label>
                            <input type="text" name="responsavel" id="con_resp" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Descrição</label>
                            <textarea name="descricao" id="con_desc" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning fw-bold">Salvar Contrato</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>

<script>
$(document).ready(function() {
    const configTable = {
        language: { url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json" },
        pageLength: 25, scrollX: true, dom: 'Bfrtip',
        buttons: [
            { extend: 'excel', text: '<i class="bi bi-file-excel"></i> Excel', className: 'btn btn-success btn-sm me-1' },
            { extend: 'print', text: '<i class="bi bi-printer"></i> Imprimir', className: 'btn btn-secondary btn-sm' }
        ]
    };
    $('#tablePedidos').DataTable(configTable);
    $('#tableContratos').DataTable(configTable);
});

// Preenche Modal Pedido
function editarPedido(dados) {
    $('#ped_id').val(dados.id);
    $('#ped_of').val(dados.numero_of);
    $('#ped_data').val(dados.data_pedido);
    $('#ped_valor').val(dados.valor_bruto_pedido);
    $('#ped_rec').val(dados.valor_total_rec);
    $('#ped_pag').val(dados.forma_pagamento);
    $('#ped_obs').val(dados.observacao);
    $('#ped_obra').val(dados.obra_id); 
    $('#ped_forn').val(dados.fornecedor_id); 
    new bootstrap.Modal(document.getElementById('modalPedido')).show();
}

// Preenche Modal Contrato
function editarContrato(dados) {
    $('#con_id').val(dados.id);
    // Campos ativos novamente
    $('#con_num').val(dados.numero_contrato); 
    $('#con_desc').val(dados.descricao);
    
    $('#con_data').val(dados.data_contrato);
    $('#con_valor').val(dados.valor);
    $('#con_resp').val(dados.responsavel);
    $('#con_forn').val(dados.fornecedor_id); 
    new bootstrap.Modal(document.getElementById('modalContrato')).show();
}

function excluirItem(id, tipo) {
    if(confirm('Tem certeza? Essa ação remove o item do banco de dados.')) {
        let form = document.createElement('form'); form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="acao" value="excluir_${tipo}"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form); form.submit();
    }
}
</script>