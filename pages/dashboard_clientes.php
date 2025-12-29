<?php
// DASHBOARD IMOBILIÁRIO - V20 (COM CONTROLE DE ESCALA Y E ALTURA)
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

// --- 1. FILTROS ---
$where = "WHERE 1=1";
$params = [];
$where_vendas = "WHERE 1=1";
$params_vendas = [];

$dt_ini = $_GET['dt_ini'] ?? date('Y-01-01');
$dt_fim = $_GET['dt_fim'] ?? date('Y-12-31');

// Filtros Data
if (!empty($dt_ini)) { 
    $where .= " AND (p.data_vencimento >= ? OR p.data_pagamento >= ?)"; 
    $params[] = $dt_ini; $params[] = $dt_ini;
    $where_vendas .= " AND v.data_contrato >= ?"; $params_vendas[] = $dt_ini;
}
if (!empty($dt_fim)) { 
    $where .= " AND (p.data_vencimento <= ? OR p.data_pagamento <= ?)"; 
    $params[] = $dt_fim; $params[] = $dt_fim;
    $where_vendas .= " AND v.data_contrato <= ?"; $params_vendas[] = $dt_fim;
}

$filtro_emp = $_GET['filtro_emp'] ?? '';
if (!empty($filtro_emp)) { 
    $where .= " AND v.nome_empresa = ?"; $params[] = $filtro_emp;
    $where_vendas .= " AND v.nome_empresa = ?"; $params_vendas[] = $filtro_emp;
}

$filtro_status = $_GET['filtro_status'] ?? '';
if ($filtro_status == 'pago') { $where .= " AND p.valor_pago > 0"; } 
elseif ($filtro_status == 'vencido') { $where .= " AND p.data_vencimento < CURDATE() AND p.valor_pago < p.valor_parcela"; } 
elseif ($filtro_status == 'aberto') { $where .= " AND p.data_vencimento >= CURDATE() AND p.valor_pago < p.valor_parcela"; }

// --- 2. CONSULTAS ---

// KPI's
$sql_kpi = "SELECT 
                SUM(p.valor_pago) as total_recebido,
                SUM(CASE WHEN p.data_vencimento < CURDATE() AND p.valor_pago < p.valor_parcela THEN (p.valor_parcela - p.valor_pago) ELSE 0 END) as total_vencido,
                SUM(CASE WHEN p.data_vencimento >= CURDATE() AND p.valor_pago < p.valor_parcela THEN (p.valor_parcela - p.valor_pago) ELSE 0 END) as total_a_receber
            FROM parcelas_imob p JOIN vendas_imob v ON p.venda_id = v.id $where";
$stmt = $pdo->prepare($sql_kpi); $stmt->execute($params); $kpi = $stmt->fetch(PDO::FETCH_ASSOC);

$total_recebido = $kpi['total_recebido'] ?? 0;
$total_vencido = $kpi['total_vencido'] ?? 0;
$total_a_receber = $kpi['total_a_receber'] ?? 0;
$total_geral = $total_recebido + $total_vencido + $total_a_receber;

// 1. FLUXO MENSAL
$sql_mes = "SELECT DATE_FORMAT(p.data_vencimento, '%Y-%m') as mes_ref, DATE_FORMAT(p.data_vencimento, '%m/%Y') as mes_label,
            SUM(p.valor_parcela) as total_geral, SUM(p.valor_pago) as recebido,
            SUM(CASE WHEN p.valor_pago < p.valor_parcela THEN (p.valor_parcela - p.valor_pago) ELSE 0 END) as a_receber
            FROM parcelas_imob p JOIN vendas_imob v ON p.venda_id = v.id $where GROUP BY mes_ref ORDER BY mes_ref ASC";
$stmt = $pdo->prepare($sql_mes); $stmt->execute($params); $dados_mes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. POR EMPREENDIMENTO
$sql_emp = "SELECT v.nome_empresa, SUM(p.valor_pago) as recebido,
            SUM(CASE WHEN p.data_vencimento < CURDATE() AND p.valor_pago < p.valor_parcela THEN (p.valor_parcela - p.valor_pago) ELSE 0 END) as vencido,
            SUM(CASE WHEN p.data_vencimento >= CURDATE() AND p.valor_pago < p.valor_parcela THEN (p.valor_parcela - p.valor_pago) ELSE 0 END) as a_receber
            FROM parcelas_imob p JOIN vendas_imob v ON p.venda_id = v.id $where GROUP BY v.nome_empresa ORDER BY recebido DESC";
$stmt = $pdo->prepare($sql_emp); $stmt->execute($params); $dados_emp = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. POR RESPONSÁVEL
$sql_resp = "SELECT responsavel, SUM(valor_total) as total_vendido 
             FROM vendas_imob v 
             $where_vendas 
             GROUP BY responsavel 
             ORDER BY total_vendido DESC";
$stmt = $pdo->prepare($sql_resp); $stmt->execute($params_vendas); $dados_resp = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lista_empresas = $pdo->query("SELECT DISTINCT nome_empresa FROM vendas_imob ORDER BY nome_empresa")->fetchAll();

// PREPARA JSONs
$json_mes_lbl = json_encode(array_column($dados_mes, 'mes_label'));
$json_mes_tot = json_encode(array_column($dados_mes, 'total_geral'));
$json_mes_rec = json_encode(array_column($dados_mes, 'recebido'));
$json_mes_are = json_encode(array_column($dados_mes, 'a_receber'));

$json_emp_lbl = json_encode(array_column($dados_emp, 'nome_empresa'));
$json_emp_rec = json_encode(array_column($dados_emp, 'recebido'));
$json_emp_ven = json_encode(array_column($dados_emp, 'vencido'));
$json_emp_fut = json_encode(array_column($dados_emp, 'a_receber'));

$lbl_resp = []; $val_resp = [];
foreach($dados_resp as $r) {
    $lbl_resp[] = !empty($r['responsavel']) ? $r['responsavel'] : 'NÃO INFORMADO';
    $val_resp[] = $r['total_vendido'];
}
$json_resp_lbl = json_encode($lbl_resp);
$json_resp_val = json_encode($val_resp);
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>

<div class="container-fluid p-4" style="background-color: #f4f6f9;">
    
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-body bg-white py-3">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="dashboard_clientes">
                
                <div class="col-md-2">
                    <label class="fw-bold small text-muted">Início</label>
                    <input type="date" name="dt_ini" class="form-control" value="<?= $dt_ini ?>">
                </div>
                <div class="col-md-2">
                    <label class="fw-bold small text-muted">Fim</label>
                    <input type="date" name="dt_fim" class="form-control" value="<?= $dt_fim ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="fw-bold small text-muted">Status</label>
                    <select name="filtro_status" class="form-select">
                        <option value="">-- Todos --</option>
                        <option value="pago" <?= $filtro_status=='pago'?'selected':'' ?>>✅ Recebidos</option>
                        <option value="vencido" <?= $filtro_status=='vencido'?'selected':'' ?>>🔴 Vencidos</option>
                        <option value="aberto" <?= $filtro_status=='aberto'?'selected':'' ?>>📅 A Receber</option>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="fw-bold small text-muted">Empresa</label>
                    <select name="filtro_emp" class="form-select">
                        <option value="">-- Todas --</option>
                        <?php foreach($lista_empresas as $e): ?>
                            <option value="<?php echo $e['nome_empresa']; ?>" <?php echo ($filtro_emp == $e['nome_empresa'])?'selected':''; ?>>
                                <?php echo mb_strimwidth($e['nome_empresa'], 0, 45, "..."); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <button class="btn btn-dark w-100 fw-bold"><i class="bi bi-funnel-fill"></i> ATUALIZAR</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-5 border-success h-100">
                <div class="card-body">
                    <small class="text-uppercase fw-bold text-success ls-1">Recebido</small>
                    <h3 class="fw-bold text-dark mt-1 mb-0">R$ <?php echo number_format($total_recebido, 2, ',', '.'); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-5 border-primary h-100">
                <div class="card-body">
                    <small class="text-uppercase fw-bold text-primary ls-1">A Receber</small>
                    <h3 class="fw-bold text-dark mt-1 mb-0">R$ <?php echo number_format($total_a_receber, 2, ',', '.'); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-5 border-danger h-100">
                <div class="card-body">
                    <small class="text-uppercase fw-bold text-danger ls-1">Inadimplência</small>
                    <h3 class="fw-bold text-dark mt-1 mb-0">R$ <?php echo number_format($total_vencido, 2, ',', '.'); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-5 border-secondary h-100">
                <div class="card-body">
                    <small class="text-uppercase fw-bold text-secondary ls-1">Total Geral</small>
                    <h3 class="fw-bold text-dark mt-1 mb-0">R$ <?php echo number_format($total_geral, 2, ',', '.'); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow border-0">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark m-0"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>1. FLUXO DE CAIXA MENSAL</h5>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm" style="width: 130px;" title="Escala Y (Teto)">
                            <span class="input-group-text bg-light"><i class="bi bi-rulers"></i></span>
                            <input type="number" class="form-control" placeholder="Auto" onchange="mudarEscala('chartFluxo', this.value)">
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="mudarAltura('containerFluxo', -50)" title="Diminuir"><i class="bi bi-dash"></i></button>
                            <button class="btn btn-outline-secondary" onclick="mudarAltura('containerFluxo', 50)" title="Aumentar"><i class="bi bi-plus"></i></button>
                        </div>
                        <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartFluxo', 'Fluxo de Caixa')"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button class="btn btn-light btn-sm border" onclick="$('#bodyFluxo').slideToggle()"><i class="bi bi-chevron-up"></i></button>
                    </div>
                </div>
                <div class="card-body" id="bodyFluxo">
                    <div id="containerFluxo" style="height: 400px; transition: height 0.3s;">
                        <canvas id="chartFluxo"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow border-0 border-start border-4 border-info">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark m-0"><i class="bi bi-bar-chart-fill me-2 text-info"></i>2. RESUMO GERAL</h5>
                    <div class="d-flex align-items-center gap-2">
                        <div class="form-check form-switch m-0 me-2">
                            <input class="form-check-input" type="checkbox" id="checkFocar" onchange="toggleTotalResumo()">
                            <label class="form-check-label small fw-bold text-muted" for="checkFocar">FOCAR</label>
                        </div>
                        <div class="input-group input-group-sm" style="width: 130px;">
                            <span class="input-group-text bg-light"><i class="bi bi-rulers"></i></span>
                            <input type="number" class="form-control" placeholder="Auto" onchange="mudarEscala('chartResumo', this.value)">
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="mudarAltura('containerResumo', -50)"><i class="bi bi-dash"></i></button>
                            <button class="btn btn-outline-secondary" onclick="mudarAltura('containerResumo', 50)"><i class="bi bi-plus"></i></button>
                        </div>
                        <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartResumo', 'Resumo Geral')"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button class="btn btn-light btn-sm border" onclick="$('#bodyResumo').slideToggle()"><i class="bi bi-chevron-up"></i></button>
                    </div>
                </div>
                <div class="card-body" id="bodyResumo">
                    <div id="containerResumo" style="height: 400px; transition: height 0.3s;">
                        <canvas id="chartResumo"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow border-0">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark m-0"><i class="bi bi-building me-2 text-secondary"></i>3. POR EMPREENDIMENTO</h5>
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="mudarAltura('containerEmpresa', -50)"><i class="bi bi-dash"></i></button>
                            <button class="btn btn-outline-secondary" onclick="mudarAltura('containerEmpresa', 50)"><i class="bi bi-plus"></i></button>
                        </div>
                        <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartEmpresa', 'Por Empreendimento')"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button class="btn btn-light btn-sm border" onclick="$('#bodyEmpresa').slideToggle()"><i class="bi bi-chevron-up"></i></button>
                    </div>
                </div>
                <div class="card-body" id="bodyEmpresa">
                    <div id="containerEmpresa" style="height: 500px; transition: height 0.3s;">
                        <canvas id="chartEmpresa"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow border-0">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark m-0"><i class="bi bi-list-ol me-2 text-warning"></i>4. RANKING DE VENDAS (DETALHADO)</h5>
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="mudarAltura('containerRespBarra', -50)"><i class="bi bi-dash"></i></button>
                            <button class="btn btn-outline-secondary" onclick="mudarAltura('containerRespBarra', 50)"><i class="bi bi-plus"></i></button>
                        </div>
                        <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartRespBarra', 'Ranking de Vendas')"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button class="btn btn-light btn-sm border" onclick="$('#bodyRespBarra').slideToggle()"><i class="bi bi-chevron-up"></i></button>
                    </div>
                </div>
                <div class="card-body" id="bodyRespBarra">
                    <div id="containerRespBarra" style="height: 500px; transition: height 0.3s;">
                        <canvas id="chartRespBarra"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow border-0 border-start border-4 border-dark">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark m-0"><i class="bi bi-bar-chart-fill me-2 text-dark"></i>5. TOTAL GERAL POR RESPONSÁVEL</h5>
                    <div class="d-flex align-items-center gap-2">
                         <div class="input-group input-group-sm" style="width: 130px;">
                            <span class="input-group-text bg-light"><i class="bi bi-rulers"></i></span>
                            <input type="number" class="form-control" placeholder="Auto" onchange="mudarEscala('chartRespVertical', this.value)">
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="mudarAltura('containerRespVertical', -50)"><i class="bi bi-dash"></i></button>
                            <button class="btn btn-outline-secondary" onclick="mudarAltura('containerRespVertical', 50)"><i class="bi bi-plus"></i></button>
                        </div>
                        <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartRespVertical', 'Total Geral por Responsável')"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button class="btn btn-light btn-sm border" onclick="$('#bodyRespVertical').slideToggle()"><i class="bi bi-chevron-up"></i></button>
                    </div>
                </div>
                <div class="card-body" id="bodyRespVertical">
                    <div id="containerRespVertical" style="height: 500px; transition: height 0.3s;">
                        <canvas id="chartRespVertical"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="modal fade" id="modalGrafico_Imob" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-dark text-white py-2"><h5 class="modal-title fw-bold" id="modalLabel_Imob">Visualização Expandida</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-white" style="height: 80vh;"><canvas id="modalCanvas_Imob"></canvas></div></div></div></div>

<script>
{
    const fmtBRL_Imob = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(val);
    const fmtCompact_Imob = (val) => new Intl.NumberFormat('pt-BR', { notation: "compact", compactDisplay: "short", maximumFractionDigits: 1 }).format(val);
    const statusFiltro = "<?php echo $filtro_status; ?>";

    try { Chart.register(ChartDataLabels); } catch(e){}
    Chart.defaults.font.family = "'Segoe UI', sans-serif";
    Chart.defaults.color = '#555';

    // OPÇÕES COMUNS
    const commonOptions = {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            tooltip: { callbacks: { label: (c) => fmtBRL_Imob(c.raw) } },
            datalabels: { anchor: 'end', align: 'end', formatter: (val) => val > 0 ? fmtCompact_Imob(val) : '', font: { size: 10, weight: 'bold' } }
        },
        scales: { 
            x: { grid: { display: false } }, 
            y: { beginAtZero: true, grid: { display: true, borderDash: [5, 5], color: '#e5e5e5', drawBorder: false } } 
        }
    };

    // DADOS RESUMO
    const resumoData = {
        labels: ['Total Geral', 'Recebido', 'A Receber', 'Vencido'],
        datasets: [{
            label: 'Valores',
            data: [<?php echo $total_geral; ?>, <?php echo $total_recebido; ?>, <?php echo $total_a_receber; ?>, <?php echo $total_vencido; ?>],
            backgroundColor: ['#0d1b2a', '#198754', '#ffc107', '#dc3545'], 
            borderRadius: 6,
            maxBarThickness: 100 
        }]
    };

    // CONFIGS DOS GRÁFICOS
    const chartConfigs = {
        'chartResumo': { type: 'bar', data: resumoData, options: { ...commonOptions, plugins: { ...commonOptions.plugins, legend: { display: false } } } },
        
        'chartFluxo': {
            type: 'bar',
            data: {
                labels: <?php echo $json_mes_lbl; ?>,
                datasets: [
                    { label: 'Total (Carteira)', data: <?php echo $json_mes_tot; ?>, backgroundColor: '#0d1b2a', borderRadius: 4, barPercentage: 0.6, categoryPercentage: 0.8, order: 0 },
                    { label: 'Recebido', data: <?php echo $json_mes_rec; ?>, backgroundColor: '#198754', borderRadius: 4, barPercentage: 0.6, categoryPercentage: 0.8, hidden: (statusFiltro === 'vencido' || statusFiltro === 'aberto'), order: 1 },
                    { label: 'A Receber', data: <?php echo $json_mes_are; ?>, backgroundColor: '#ffc107', borderRadius: 4, barPercentage: 0.6, categoryPercentage: 0.8, hidden: (statusFiltro === 'pago'), order: 2 }
                ]
            },
            options: { ...commonOptions, plugins: { ...commonOptions.plugins, legend: { position: 'top' } } }
        },
        
        'chartEmpresa': {
            type: 'bar',
            data: {
                labels: <?php echo $json_emp_lbl; ?>,
                datasets: [
                    { label: 'Recebido', data: <?php echo $json_emp_rec; ?>, backgroundColor: '#198754', barPercentage: 0.7, hidden: (statusFiltro === 'vencido' || statusFiltro === 'aberto') },
                    { label: 'Futuro', data: <?php echo $json_emp_fut; ?>, backgroundColor: '#0d1b2a', barPercentage: 0.7, hidden: (statusFiltro === 'pago' || statusFiltro === 'vencido') },
                    { label: 'Vencido', data: <?php echo $json_emp_ven; ?>, backgroundColor: '#dc3545', barPercentage: 0.7, hidden: (statusFiltro === 'pago' || statusFiltro === 'aberto') }
                ]
            },
            options: { indexAxis: 'y', ...commonOptions, scales: { x: { grid: { display: false } }, y: { grid: { display: false } } } }
        },

        'chartRespBarra': {
            type: 'bar',
            data: {
                labels: <?php echo $json_resp_lbl; ?>,
                datasets: [{
                    label: 'Total Vendido',
                    data: <?php echo $json_resp_val; ?>,
                    backgroundColor: '#415a77', 
                    borderRadius: 4,
                    barPercentage: 0.6
                }]
            },
            options: { indexAxis: 'y', ...commonOptions, scales: { x: { grid: { display: true, borderDash: [2,2] } }, y: { grid: { display: false } } } }
        },

        'chartRespVertical': {
            type: 'bar',
            data: {
                labels: <?php echo $json_resp_lbl; ?>,
                datasets: [{
                    label: 'Vendas (R$)',
                    data: <?php echo $json_resp_val; ?>,
                    backgroundColor: '#0d1b2a',
                    borderRadius: 6,
                    maxBarThickness: 80
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + fmtBRL_Imob(c.raw) } },
                    datalabels: {
                        color: '#333', anchor: 'end', align: 'top', font: { weight: 'bold', size: 11 },
                        formatter: (value, ctx) => {
                            let sum = 0;
                            ctx.chart.data.datasets[0].data.map(d => { sum += Number(d); });
                            let percentage = (value * 100 / sum).toFixed(1) + "%";
                            if(percentage === "0.0%") return "";
                            return fmtCompact_Imob(value) + "\n(" + percentage + ")";
                        },
                        textAlign: 'center'
                    }
                },
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { display: true, borderDash: [5, 5] } } }
            }
        }
    };

    // INICIALIZA GRÁFICOS
    const chartInstances_Imob = {};
    for (const [id, config] of Object.entries(chartConfigs)) {
        const ctx = document.getElementById(id);
        if (ctx) chartInstances_Imob[id] = new Chart(ctx, config);
    }

    // --- FUNÇÕES DE CONTROLE ---
    
    // 1. ALTERAR ESCALA (TETO Y)
    window.mudarEscala = function(chartId, valor) {
        const chart = chartInstances_Imob[chartId];
        if(!chart) return;
        
        if(valor && valor > 0) {
            chart.options.scales.y.max = Number(valor);
            if(chart.config.type === 'bar' && chart.options.indexAxis === 'y') {
                 // Se for barra horizontal (Ranking), muda o X
                 chart.options.scales.x.max = Number(valor);
                 delete chart.options.scales.y.max;
            }
        } else {
            delete chart.options.scales.y.max;
            delete chart.options.scales.x.max;
        }
        chart.update();
    };

    // 2. ALTERAR ALTURA DO CONTAINER
    window.mudarAltura = function(containerId, pixels) {
        const el = document.getElementById(containerId);
        if(el) { 
            let h = el.offsetHeight + pixels; 
            if(h < 200) h = 200; 
            el.style.height = h + 'px'; 
        }
    };

    window.toggleTotalResumo = function() {
        const chart = chartInstances_Imob['chartResumo'];
        const isChecked = document.getElementById('checkFocar').checked;
        if (isChecked) {
            chart.data.labels = ['Recebido', 'A Receber', 'Vencido'];
            chart.data.datasets[0].data = [<?php echo $total_recebido; ?>, <?php echo $total_a_receber; ?>, <?php echo $total_vencido; ?>];
            chart.data.datasets[0].backgroundColor = ['#198754', '#ffc107', '#dc3545'];
        } else {
            chart.data.labels = ['Total Geral', 'Recebido', 'A Receber', 'Vencido'];
            chart.data.datasets[0].data = [<?php echo $total_geral; ?>, <?php echo $total_recebido; ?>, <?php echo $total_a_receber; ?>, <?php echo $total_vencido; ?>];
            chart.data.datasets[0].backgroundColor = ['#0d1b2a', '#198754', '#ffc107', '#dc3545'];
        }
        chart.update();
    };

    window.expandirGrafico = function(chartId, titulo) {
        const configOriginal = chartConfigs[chartId];
        if (!configOriginal) return;
        document.getElementById('modalLabel_Imob').innerText = titulo;
        const modalCanvas = document.getElementById('modalCanvas_Imob');
        if (window.modalChartInstance) window.modalChartInstance.destroy();
        let chartHeight = document.getElementById(chartId).parentElement.offsetHeight;
        modalCanvas.style.height = (chartHeight > window.innerHeight * 0.8) ? chartHeight + "px" : "80vh";
        window.modalChartInstance = new Chart(modalCanvas, { type: configOriginal.type, data: configOriginal.data, options: { ...configOriginal.options, maintainAspectRatio: false, plugins: { ...configOriginal.options.plugins, legend: { display: true, position: 'top' } } } });
        new bootstrap.Modal(document.getElementById('modalGrafico_Imob')).show();
    };
}
</script>

<style>
@media print { .btn, form, a { display: none !important; } .card { border: none !important; box-shadow: none !important; } canvas { max-width: 100% !important; height: auto !important; page-break-inside: avoid; } body { background-color: white !important; } }
</style>