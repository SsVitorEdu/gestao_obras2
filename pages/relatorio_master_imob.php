<?php
// pages/relatorio_master_imob.php
// RELATÓRIO MESTRE IMOBILIÁRIO (FINANCEIRO GERAL) - VERSÃO FINAL "MÃE"
// Funcionalidades: Cartões de Totais + Agrupamento + Responsável + Visual Construtora
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

// --- 1. FILTROS ---
$where = "WHERE 1=1";
$params = [];

// Data
$dt_tipo = $_GET['dt_tipo'] ?? 'vencimento'; // vencimento, pagamento ou todos
$dt_ini  = $_GET['dt_ini'] ?? date('Y-m-01');
$dt_fim  = $_GET['dt_fim'] ?? date('Y-m-t');

if (!empty($dt_ini) && !empty($dt_fim)) { 
    if ($dt_tipo == 'todos') {
        // Busca se Venceu OU se Pagou no período
        $where .= " AND (p.data_vencimento BETWEEN ? AND ? OR p.data_pagamento BETWEEN ? AND ?)";
        $params[] = $dt_ini; $params[] = $dt_fim;
        $params[] = $dt_ini; $params[] = $dt_fim;
    } else {
        $coluna_data = ($dt_tipo == 'pagamento') ? 'p.data_pagamento' : 'p.data_vencimento';
        $where .= " AND $coluna_data BETWEEN ? AND ?"; 
        $params[] = $dt_ini; 
        $params[] = $dt_fim; 
    }
}

// Filtro por Empresa (Empreendimento)
$filtro_empresa = $_GET['filtro_empresa'] ?? '';
if (!empty($filtro_empresa)) {
    $where .= " AND v.nome_empresa LIKE ?";
    $params[] = "%$filtro_empresa%";
}

// Checkbox Agrupar
$agrupar = isset($_GET['agrupar']) && $_GET['agrupar'] == 'sim';

// --- 2. CONSULTA GERAL (SEMPRE TRAZ TUDO PARA CALCULAR OS TOTAIS CORRETAMENTE) ---
$sql = "SELECT 
            p.*,
            v.codigo_compra,
            v.nome_casa,
            v.nome_empresa,
            v.responsavel, /* NOVO CAMPO */
            c.nome as nome_cliente,
            c.cpf
        FROM parcelas_imob p
        JOIN vendas_imob v ON p.venda_id = v.id
        JOIN clientes_imob c ON v.cliente_id = c.id
        $where
        ORDER BY p.data_vencimento ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dados_brutos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. CÁLCULOS DOS KPI's (INDICADORES DO TOPO) ---
$total_recebido = 0;
$total_a_receber = 0;
$total_atrasado = 0;
$hoje = date('Y-m-d');

// Arrays para montar a tabela (Agrupada ou Detalhada)
$dados_tabela = [];

if ($agrupar) {
    // LÓGICA DE AGRUPAMENTO
    $grupos = [];
    foreach ($dados_brutos as $d) {
        // Cálculos Globais (não muda)
        $vlr_orig = $d['valor_parcela'];
        $vlr_pago = $d['valor_pago'];
        $venc = $d['data_vencimento'];
        $total_recebido += $vlr_pago;
        if ($vlr_pago < $vlr_orig) {
            $saldo = $vlr_orig - $vlr_pago;
            if ($venc < $hoje) $total_atrasado += $saldo;
            else $total_a_receber += $saldo;
        }

        // Montagem do Grupo
        $id_cli = $d['venda_id']; // Usa ID da venda para agrupar
        if (!isset($grupos[$id_cli])) {
            $grupos[$id_cli] = [
                'cliente' => $d['nome_cliente'],
                'responsavel' => $d['responsavel'],
                'empresa' => $d['nome_empresa'],
                'qtd_parc' => 0,
                'total_orig' => 0,
                'total_pago' => 0,
                'ultima_data' => $d['data_vencimento']
            ];
        }
        $grupos[$id_cli]['qtd_parc']++;
        $grupos[$id_cli]['total_orig'] += $d['valor_parcela'];
        $grupos[$id_cli]['total_pago'] += $d['valor_pago'];
        // Pega a maior data
        if ($d['data_vencimento'] > $grupos[$id_cli]['ultima_data']) {
            $grupos[$id_cli]['ultima_data'] = $d['data_vencimento'];
        }
    }
    $dados_tabela = $grupos;

} else {
    // LÓGICA DETALHADA (NORMAL)
    foreach ($dados_brutos as $d) {
        // Cálculos Globais
        $vlr_orig = $d['valor_parcela'];
        $vlr_pago = $d['valor_pago'];
        $venc = $d['data_vencimento'];
        $total_recebido += $vlr_pago;
        if ($vlr_pago < $vlr_orig) {
            $saldo = $vlr_orig - $vlr_pago;
            if ($venc < $hoje) $total_atrasado += $saldo;
            else $total_a_receber += $saldo;
        }
        $dados_tabela[] = $d;
    }
}

$total_geral = $total_recebido + $total_a_receber + $total_atrasado;
$perc_recebido = ($total_geral > 0) ? ($total_recebido / $total_geral) * 100 : 0;

// Listas para filtros
$lista_empresas = $pdo->query("SELECT DISTINCT nome_empresa FROM vendas_imob ORDER BY nome_empresa")->fetchAll();
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">

<style>
    /* CORES DA CONSTRUTORA (PRETO, CINZA, AZUL MARINHO) */
    :root {
        --cor-primaria: #0d1b2a; /* Azul Quase Preto */
        --cor-secundaria: #415a77; /* Azul Acinzentado */
        --cor-fundo: #e0e1dd;
    }

    .kpi-card { 
        border-left: 5px solid #ccc; 
        transition: transform 0.2s; 
        background-color: white;
    }
    .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .kpi-icon { font-size: 2rem; opacity: 0.2; position: absolute; right: 15px; top: 15px; }
    
    .janela-rolagem { width: 99%; height: 75vh; overflow: auto; background: white; border: 1px solid #ccc; margin: 0 auto; box-shadow: inset 0 0 10px #f0f0f0; }
    .mesa-gigante { width: 100%; min-width: 1200px; }
    
    /* Cabeçalho da Tabela - Estilo Construtora */
    .thead-corp { background-color: var(--cor-primaria) !important; color: white !important; }
    .thead-corp th { border-color: #333 !important; font-weight: 600; text-transform: uppercase; font-size: 0.85rem; }

    /* Botões */
    .btn-corp { background-color: var(--cor-primaria); color: white; border: none; }
    .btn-corp:hover { background-color: #000; color: white; }

    /* Status Visual (Mantido mas suavizado) */
    .st-pago { color: #155724; background-color: #d4edda; font-weight: bold; border: 1px solid #c3e6cb; }
    .st-atrasado { color: #721c24; background-color: #f8d7da; font-weight: bold; border: 1px solid #f5c6cb; }
    .st-aberto { color: #004085; background-color: #cce5ff; font-weight: bold; border: 1px solid #b8daff; }
    .st-parcial { color: #856404; background-color: #fff3cd; font-weight: bold; border: 1px solid #ffeeba; }
</style>

<div class="container-fluid p-4" style="background-color: #f4f6f9;">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold m-0" style="color: var(--cor-primaria);"><i class="bi bi-building"></i> RELATÓRIO FINANCEIRO MASTER</h3>
            <small class="text-secondary">Visão consolidada de recebíveis e fluxo de caixa.</small>
        </div>
        <div>
            <a href="index.php?page=clientes" class="btn btn-outline-dark fw-bold shadow-sm"><i class="bi bi-arrow-left"></i> Voltar</a>
        </div>
    </div>

    <div class="row mb-4 g-3">
        <div class="col-md-3">
            <div class="card shadow-sm kpi-card" style="border-left-color: #198754;">
                <div class="card-body">
                    <i class="bi bi-check-circle-fill kpi-icon text-success"></i>
                    <small class="text-uppercase fw-bold text-success" style="font-size: 0.75rem; letter-spacing: 1px;">Total Recebido</small>
                    <h3 class="mb-0 fw-bold text-dark">R$ <?php echo number_format($total_recebido, 2, ',', '.'); ?></h3>
                    <small class="text-muted"><?php echo number_format($perc_recebido, 1); ?>% do total</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm kpi-card" style="border-left-color: #0d6efd;">
                <div class="card-body">
                    <i class="bi bi-hourglass-split kpi-icon text-primary"></i>
                    <small class="text-uppercase fw-bold text-primary" style="font-size: 0.75rem; letter-spacing: 1px;">A Receber (No Prazo)</small>
                    <h3 class="mb-0 fw-bold text-dark">R$ <?php echo number_format($total_a_receber, 2, ',', '.'); ?></h3>
                    <small class="text-muted">Fluxo futuro</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm kpi-card" style="border-left-color: #dc3545;">
                <div class="card-body">
                    <i class="bi bi-exclamation-octagon-fill kpi-icon text-danger"></i>
                    <small class="text-uppercase fw-bold text-danger" style="font-size: 0.75rem; letter-spacing: 1px;">Inadimplência</small>
                    <h3 class="mb-0 fw-bold text-dark">R$ <?php echo number_format($total_atrasado, 2, ',', '.'); ?></h3>
                    <small class="text-danger fw-bold">Atenção Necessária</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm kpi-card" style="border-left-color: #333;">
                <div class="card-body">
                    <i class="bi bi-wallet2 kpi-icon text-secondary"></i>
                    <small class="text-uppercase fw-bold text-secondary" style="font-size: 0.75rem; letter-spacing: 1px;">Total Geral</small>
                    <h3 class="mb-0 fw-bold text-dark">R$ <?php echo number_format($total_geral, 2, ',', '.'); ?></h3>
                    <small class="text-muted">Volume filtrado</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3 border-0">
        <div class="card-body py-3" style="background-color: #e9ecef; border-bottom: 2px solid #ccc;">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="relatorio_master_imob">
                
                <div class="col-md-2">
                    <label class="small fw-bold text-secondary">Filtrar Data Por:</label>
                    <select name="dt_tipo" class="form-select form-select-sm fw-bold">
                        <option value="vencimento" <?php echo ($dt_tipo=='vencimento')?'selected':''; ?>>📅 Vencimento</option>
                        <option value="pagamento" <?php echo ($dt_tipo=='pagamento')?'selected':''; ?>>💰 Pagamento (Baixa)</option>
                        <option value="todos" <?php echo ($dt_tipo=='todos')?'selected':''; ?>>♾️ Todos (Venc ou Pag)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-secondary">Início</label>
                    <input type="date" name="dt_ini" class="form-control form-control-sm" value="<?php echo $dt_ini; ?>">
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-secondary">Fim</label>
                    <input type="date" name="dt_fim" class="form-control form-control-sm" value="<?php echo $dt_fim; ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="small fw-bold text-secondary">Empreendimento</label>
                    <select name="filtro_empresa" class="form-select form-select-sm">
                        <option value="">-- Todos --</option>
                        <?php foreach($lista_empresas as $e): ?>
                            <option value="<?php echo $e['nome_empresa']; ?>" <?php echo ($filtro_empresa == $e['nome_empresa'])?'selected':''; ?>>
                                <?php echo $e['nome_empresa']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="agrupar" value="sim" id="checkAgrupar" <?php echo $agrupar?'checked':''; ?>>
                        <label class="form-check-label small fw-bold text-dark" for="checkAgrupar">
                            <i class="bi bi-people-fill"></i> Agrupar por Cliente
                        </label>
                    </div>
                </div>

                <div class="col-md-2">
                    <button class="btn btn-corp btn-sm w-100 fw-bold shadow-sm"><i class="bi bi-filter"></i> FILTRAR</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="janela-rolagem">
                <table id="tabelaMaster" class="table table-hover table-bordered mb-0 mesa-gigante">
                    <thead class="thead-corp text-center sticky-top">
                        <tr>
                            <?php if($agrupar): ?>
                                <th>CLIENTE</th>
                                <th>RESPONSÁVEL</th>
                                <th>EMPREENDIMENTO</th>
                                <th>QTD PARC.</th>
                                <th>ÚLT. DATA</th>
                                <th>TOTAL ORIGINAL</th>
                                <th>TOTAL PAGO</th>
                                <th>SALDO DEVEDOR</th>
                            <?php else: ?>
                                <th>STATUS</th>
                                <th>VENCIMENTO</th>
                                <th>CLIENTE</th>
                                <th>RESPONSÁVEL</th>
                                <th>EMPREENDIMENTO</th>
                                <th>PARCELA</th>
                                <th>VALOR</th>
                                <th>PAGO</th>
                                <th>SALDO</th>
                                <th>DT PAGTO</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($agrupar) {
                            // --- EXIBIÇÃO AGRUPADA ---
                            foreach($dados_tabela as $row):
                                $saldo_grupo = $row['total_orig'] - $row['total_pago'];
                        ?>
                            <tr>
                                <td class="fw-bold"><?php echo mb_strimwidth($row['cliente'], 0, 40, "..."); ?></td>
                                <td class="text-center small text-muted"><?php echo $row['responsavel'] ?: '-'; ?></td>
                                <td><?php echo $row['empresa']; ?></td>
                                <td class="text-center"><span class="badge bg-secondary"><?php echo $row['qtd_parc']; ?></span></td>
                                <td class="text-center"><?php echo date('d/m/Y', strtotime($row['ultima_data'])); ?></td>
                                <td class="text-end text-secondary">R$ <?php echo number_format($row['total_orig'], 2, ',', '.'); ?></td>
                                <td class="text-end text-success fw-bold">R$ <?php echo number_format($row['total_pago'], 2, ',', '.'); ?></td>
                                <td class="text-end text-danger fw-bold">R$ <?php echo number_format($saldo_grupo, 2, ',', '.'); ?></td>
                            </tr>
                        <?php 
                            endforeach;
                        } else {
                            // --- EXIBIÇÃO DETALHADA (PADRÃO) ---
                            foreach($dados_tabela as $row): 
                                $saldo = $row['valor_parcela'] - $row['valor_pago'];
                                
                                // Define Status
                                if ($row['valor_pago'] >= ($row['valor_parcela'] - 0.1)) {
                                    $classe = "st-pago"; $status_txt = "PAGO";
                                } elseif ($row['valor_pago'] > 0) {
                                    $classe = "st-parcial"; $status_txt = "PARCIAL";
                                } elseif ($row['data_vencimento'] < $hoje) {
                                    $classe = "st-atrasado"; $status_txt = "ATRASADO";
                                } else {
                                    $classe = "st-aberto"; $status_txt = "A VENCER";
                                }
                        ?>
                            <tr>
                                <td class="text-center small"><span class="badge <?php echo $classe; ?> w-100 p-1"><?php echo $status_txt; ?></span></td>
                                <td class="text-center fw-bold"><?php echo date('d/m/Y', strtotime($row['data_vencimento'])); ?></td>
                                <td><?php echo mb_strimwidth($row['nome_cliente'], 0, 25, "..."); ?></td>
                                <td class="small text-muted text-center"><?php echo mb_strimwidth($row['responsavel'], 0, 15, "..."); ?></td>
                                <td><?php echo mb_strimwidth($row['nome_empresa'], 0, 20, "..."); ?></td>
                                <td class="text-center"><?php echo $row['numero_parcela']; ?></td>
                                <td class="text-end text-secondary">R$ <?php echo number_format($row['valor_parcela'], 2, ',', '.'); ?></td>
                                <td class="text-end text-success fw-bold">R$ <?php echo number_format($row['valor_pago'], 2, ',', '.'); ?></td>
                                <td class="text-end text-danger fw-bold">R$ <?php echo number_format($saldo > 0 ? $saldo : 0, 2, ',', '.'); ?></td>
                                <td class="text-center small text-muted"><?php echo $row['data_pagamento'] ? date('d/m/Y', strtotime($row['data_pagamento'])) : '-'; ?></td>
                            </tr>
                        <?php 
                            endforeach; 
                        } 
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    $('#tabelaMaster').DataTable({
        paging: true,
        pageLength: 100, 
        ordering: false, 
        dom: 'Bfrtip',
        buttons: [
            { 
                extend: 'excel', 
                text: '<i class="bi bi-file-earmark-excel"></i> Excel', 
                className: 'btn btn-success btn-sm me-1 fw-bold',
                title: 'Relatório Master Imobiliário'
            },
            { 
                extend: 'pdfHtml5', 
                text: '<i class="bi bi-file-earmark-pdf"></i> PDF', 
                className: 'btn btn-danger btn-sm me-1 fw-bold',
                orientation: 'landscape',
                pageSize: 'A4',
                title: 'Relatório Master Imobiliário'
            },
            { 
                extend: 'print', 
                text: '<i class="bi bi-printer"></i> Imprimir', 
                className: 'btn btn-secondary btn-sm fw-bold'
            }
        ],
        language: { url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json" }
    });
});
</script>