<?php
// DASHBOARD FORNECEDORES (FILTROS COMPLETOS + GRÁFICOS VERTICAIS R$)
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

// --- 1. FILTROS ---
$where_pedidos = "WHERE 1=1";
$params_pedidos = [];

$where_contratos = "WHERE 1=1";
$params_contratos = [];

// A. DATAS
$dt_ini = $_GET['dt_ini'] ?? date('Y-01-01');
$dt_fim = $_GET['dt_fim'] ?? date('Y-12-31');

if (!empty($dt_ini)) { 
    $where_pedidos .= " AND p.data_pedido >= ?"; $params_pedidos[] = $dt_ini; 
    $where_contratos .= " AND data_contrato >= ?"; $params_contratos[] = $dt_ini;
}
if (!empty($dt_fim)) { 
    $where_pedidos .= " AND p.data_pedido <= ?"; $params_pedidos[] = $dt_fim; 
    $where_contratos .= " AND data_contrato <= ?"; $params_contratos[] = $dt_fim;
}

// B. EMPRESA
$filtro_emp = $_GET['filtro_emp'] ?? '';
if (!empty($filtro_emp)) { 
    $where_pedidos .= " AND p.empresa_id = ?"; $params_pedidos[] = $filtro_emp;
}

// C. OBRA
$filtro_obra = $_GET['filtro_obra'] ?? '';
if (!empty($filtro_obra)) { 
    $where_pedidos .= " AND p.obra_id = ?"; $params_pedidos[] = $filtro_obra;
    $where_contratos .= " AND fornecedor_id IN (SELECT DISTINCT fornecedor_id FROM pedidos WHERE obra_id = ?)";
    $params_contratos[] = $filtro_obra;
}

// D. FORNECEDOR
$filtro_forn = $_GET['filtro_forn'] ?? '';
if (!empty($filtro_forn)) { 
    $where_pedidos .= " AND p.fornecedor_id = ?"; $params_pedidos[] = $filtro_forn;
    $where_contratos .= " AND fornecedor_id = ?"; $params_contratos[] = $filtro_forn;
}

// E. PAGAMENTO
$filtro_pag = $_GET['filtro_pag'] ?? '';
if (!empty($filtro_pag)) { 
    $where_pedidos .= " AND p.forma_pagamento = ?"; $params_pedidos[] = $filtro_pag;
}

// --- 2. DADOS ---

// G1: FINANCEIRO GERAL
$sql_fin = "SELECT 
                SUM(p.valor_bruto_pedido) as total_bruto,
                SUM(p.valor_total_rec) as total_executado
            FROM pedidos p
            $where_pedidos";
$stmt = $pdo->prepare($sql_fin);
$stmt->execute($params_pedidos);
$fin = $stmt->fetch(PDO::FETCH_ASSOC);

$total_bruto = $fin['total_bruto'] ?? 0;
$total_executado = $fin['total_executado'] ?? 0;
$total_saldo = $total_bruto - $total_executado;

// G2: PAGAMENTO
$sql_pag = "SELECT forma_pagamento, SUM(valor_bruto_pedido) as total
            FROM pedidos p
            $where_pedidos AND p.forma_pagamento != ''
            GROUP BY forma_pagamento 
            ORDER BY total DESC";
$stmt = $pdo->prepare($sql_pag);
$stmt->execute($params_pedidos);
$dados_pag = $stmt->fetchAll(PDO::FETCH_ASSOC);

// G3: FORNECEDORES
$sql_forn = "SELECT f.nome, SUM(p.valor_bruto_pedido) as total
             FROM pedidos p
             JOIN fornecedores f ON p.fornecedor_id = f.id
             $where_pedidos
             GROUP BY f.id 
             ORDER BY total DESC"; 
$stmt = $pdo->prepare($sql_forn);
$stmt->execute($params_pedidos);
$dados_forn = $stmt->fetchAll(PDO::FETCH_ASSOC);

// G4: CONTRATOS
$sql_resp = "SELECT responsavel, SUM(valor) as total
             FROM contratos
             $where_contratos
             GROUP BY responsavel
             ORDER BY total DESC";
$stmt = $pdo->prepare($sql_resp);
$stmt->execute($params_contratos);
$dados_resp = $stmt->fetchAll(PDO::FETCH_ASSOC);


// LISTAS SELECTS
$emp_list  = $pdo->query("SELECT id, nome FROM empresas ORDER BY nome")->fetchAll();
$obras_list = $pdo->query("SELECT id, nome FROM obras ORDER BY nome")->fetchAll();
$forn_list = $pdo->query("SELECT id, nome FROM fornecedores ORDER BY nome")->fetchAll();
$pag_list = $pdo->query("SELECT DISTINCT forma_pagamento FROM pedidos WHERE forma_pagamento != '' ORDER BY forma_pagamento")->fetchAll();

// JSON
$json_fin_vals = json_encode([$total_bruto, $total_executado, $total_saldo]);

$json_pag_lbl = json_encode(array_column($dados_pag, 'forma_pagamento'));
$json_pag_val = json_encode(array_column($dados_pag, 'total'));

$json_forn_lbl = json_encode(array_column($dados_forn, 'nome'));
$json_forn_val = json_encode(array_column($dados_forn, 'total'));

$json_resp_lbl = json_encode(array_column($dados_resp, 'responsavel'));
$json_resp_val = json_encode(array_column($dados_resp, 'total'));
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>

<div class="container-fluid p-4 bg-light">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="text-dark fw-bold m-0"><i class="bi bi-graph-up-arrow text-primary"></i> ANÁLISE DE FORNECEDORES</h3>
            <span class="text-muted small">Visão Financeira Completa</span>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-outline-dark fw-bold me-2"><i class="bi bi-printer"></i> IMPRIMIR</button>
            <a href="index.php?page=fornecedores" class="btn btn-dark fw-bold"><i class="bi bi-arrow-left"></i> VOLTAR</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4 border-primary">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="dashboard_graficos">
                
                <div class="col-md-2"><label class="fw-bold small text-muted">Início</label><input type="date" name="dt_ini" class="form-control" value="<?php echo $dt_ini; ?>"></div>
                <div class="col-md-2"><label class="fw-bold small text-muted">Fim</label><input type="date" name="dt_fim" class="form-control" value="<?php echo $dt_fim; ?>"></div>
                
                <div class="col-md-4">
                    <label class="fw-bold small text-muted">Empresa</label>
                    <select name="filtro_emp" class="form-select">
                        <option value="">-- Todas --</option>
                        <?php foreach($emp_list as $e): ?><option value="<?php echo $e['id']; ?>" <?php echo ($filtro_emp==$e['id'])?'selected':''; ?>><?php echo substr($e['nome'],0,35); ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="fw-bold small text-muted">Obra</label>
                    <select name="filtro_obra" class="form-select">
                        <option value="">-- Todas --</option>
                        <?php foreach($obras_list as $o): ?><option value="<?php echo $o['id']; ?>" <?php echo ($filtro_obra==$o['id'])?'selected':''; ?>><?php echo substr($o['nome'],0,35); ?></option><?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="fw-bold small text-muted">Fornecedor</label>
                    <select name="filtro_forn" class="form-select">
                        <option value="">-- Todos --</option>
                        <?php foreach($forn_list as $f): ?><option value="<?php echo $f['id']; ?>" <?php echo ($filtro_forn==$f['id'])?'selected':''; ?>><?php echo substr($f['nome'],0,30); ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="fw-bold small text-muted">Forma de Pagamento</label>
                    <select name="filtro_pag" class="form-select">
                        <option value="">-- Todas --</option>
                        <?php foreach($pag_list as $p): ?><option value="<?php echo $p['forma_pagamento']; ?>" <?php echo ($filtro_pag==$p['forma_pagamento'])?'selected':''; ?>><?php echo $p['forma_pagamento']; ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-5">
                    <button class="btn btn-primary fw-bold w-100"><i class="bi bi-funnel"></i> FILTRAR DADOS</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark m-0 text-center">RESUMO FINANCEIRO</h5>
                    <div class="d-flex gap-1">
                        <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartFin', 'RESUMO FINANCEIRO')" title="Expandir"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button class="btn btn-light btn-sm border" onclick="$('#bodyFin').slideToggle()" title="Ocultar"><i class="bi bi-eye-slash"></i></button>
                    </div>
                </div>
                <div class="card-body" id="bodyFin">
                    <div style="height: 400px;">
                        <canvas id="chartFin"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6 mb-4">
            <div class="card shadow h-100 border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark m-0">FORMA DE PAGAMENTO</h5>
                    <div class="d-flex gap-1">
                        <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartPag', 'FORMA DE PAGAMENTO')" title="Expandir"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button class="btn btn-light btn-sm border" onclick="$('#bodyPag').slideToggle()"><i class="bi bi-eye-slash"></i></button>
                    </div>
                </div>
                <div class="card-body" id="bodyPag">
                    <div style="height: 350px;">
                        <canvas id="chartPag"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card shadow h-100 border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark m-0">CONTRATOS POR RESPONSÁVEL</h5>
                    <div class="d-flex gap-1">
                        <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartResp', 'CONTRATOS POR RESPONSÁVEL')" title="Expandir"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button class="btn btn-light btn-sm border" onclick="$('#bodyResp').slideToggle()"><i class="bi bi-eye-slash"></i></button>
                    </div>
                </div>
                <div class="card-body" id="bodyResp">
                    <div style="height: 350px;">
                        <canvas id="chartResp"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12 mb-4">
            <div class="card shadow border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark m-0"><i class="bi bi-trophy"></i> RANKING DE GASTO POR FORNECEDOR</h5>
                    <div class="d-flex gap-1">
                        <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartForn', 'RANKING FORNECEDORES')" title="Expandir"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button class="btn btn-light btn-sm border" onclick="$('#bodyForn').slideToggle()"><i class="bi bi-eye-slash"></i></button>
                    </div>
                </div>
                <div class="card-body" id="bodyForn">
                    <div style="height: 450px;">
                        <canvas id="chartForn"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalGrafico" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h5 class="modal-title fw-bold" id="modalLabel">Visualização Expandida</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-white" style="height: 80vh;">
                <canvas id="modalCanvas"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// FORMATADORES
const fmtBRL = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(val);
const fmtCompact = (val) => new Intl.NumberFormat('pt-BR', { notation: "compact", compactDisplay: "short", maximumFractionDigits: 1 }).format(val);

Chart.register(ChartDataLabels);
Chart.defaults.font.family = "'Segoe UI', sans-serif";

// LISTA DE GRÁFICOS
const charts = {};

// CONFIG LABELS VERTICAL (TOPO)
const verticalLabels = {
    anchor: 'end', 
    align: 'top',
    formatter: (val) => fmtCompact(val),
    color: '#333', 
    font: { weight: 'bold' }
};

// --- G1: FINANCEIRO ---
charts['chartFin'] = new Chart(document.getElementById('chartFin'), {
    type: 'bar',
    data: {
        labels: ['VALOR BRUTO', 'TOTAL EXECUTADO', 'SALDO A EXECUTAR'],
        datasets: [{
            data: <?php echo $json_fin_vals; ?>,
            backgroundColor: ['#0d6efd', '#198754', '#dc3545'],
            borderRadius: 6,
            barPercentage: 0.6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (c) => fmtBRL(c.raw) } },
            datalabels: { ...verticalLabels, formatter: (val) => fmtBRL(val) }
        },
        scales: { y: { beginAtZero: true, grid: { color: '#f0f0f0' } } }
    }
});

// --- G2: PAGAMENTOS ---
charts['chartPag'] = new Chart(document.getElementById('chartPag'), {
    type: 'bar',
    data: {
        labels: <?php echo $json_pag_lbl; ?>,
        datasets: [{
            label: 'Total Gasto',
            data: <?php echo $json_pag_val; ?>,
            backgroundColor: '#6610f2', // Roxo
            borderRadius: 4,
            barPercentage: 0.7
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (c) => fmtBRL(c.raw) } },
            datalabels: verticalLabels
        },
        scales: { y: { beginAtZero: true } }
    }
});

// --- G3: FORNECEDORES ---
charts['chartForn'] = new Chart(document.getElementById('chartForn'), {
    type: 'bar',
    data: {
        labels: <?php echo $json_forn_lbl; ?>,
        datasets: [{
            label: 'Total Gasto',
            data: <?php echo $json_forn_val; ?>,
            backgroundColor: '#0d6efd',
            borderRadius: 4,
            barPercentage: 0.8
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, 
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (c) => fmtBRL(c.raw) } },
            datalabels: verticalLabels
        },
        scales: { y: { beginAtZero: true } }
    }
});

// --- G4: CONTRATOS ---
charts['chartResp'] = new Chart(document.getElementById('chartResp'), {
    type: 'bar',
    data: {
        labels: <?php echo $json_resp_lbl; ?>,
        datasets: [{
            label: 'Total Contratado',
            data: <?php echo $json_resp_val; ?>,
            backgroundColor: '#ffc107',
            borderRadius: 4,
            barPercentage: 0.5
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (c) => fmtBRL(c.raw) } },
            datalabels: { ...verticalLabels, align: 'top' }
        },
        scales: { y: { grid: { borderDash: [5, 5] } }, x: { grid: { display: false } } }
    }
});

// --- POP-UP ---
let modalChartInstance = null;

function expandirGrafico(chartId, titulo) {
    const sourceChart = charts[chartId];
    if (!sourceChart) { alert('Carregando...'); return; }
    
    document.getElementById('modalLabel').innerText = titulo;
    const modalCanvas = document.getElementById('modalCanvas');
    if (modalChartInstance) { modalChartInstance.destroy(); }

    modalChartInstance = new Chart(modalCanvas, {
        type: sourceChart.config.type,
        data: sourceChart.config.data, 
        options: {
            ...sourceChart.config.options, 
            maintainAspectRatio: false, 
            plugins: {
                ...sourceChart.config.options.plugins,
                legend: { display: true, position: 'top' } 
            }
        }
    });
    new bootstrap.Modal(document.getElementById('modalGrafico')).show();
}
</script>

<style>
@media print {
    .btn, form, a { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    canvas { max-width: 100% !important; height: auto !important; page-break-inside: avoid; }
}
</style>