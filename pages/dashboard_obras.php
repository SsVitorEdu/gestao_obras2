<?php
// DASHBOARD OBRAS - VERSÃO V7 (TOTAIS DISCRETOS NO TÍTULO)
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); 

// --- 1. CONEXÃO ---
if (!isset($pdo)) {
    $db_file = __DIR__ . '/../includes/db.php';
    if (file_exists($db_file)) include $db_file;
}

// Filtros
$where = "WHERE 1=1";
$params = [];

$dt_ini = $_GET['dt_ini'] ?? date('Y-01-01');
$dt_fim = $_GET['dt_fim'] ?? date('Y-12-31');

if (!empty($dt_ini)) { $where .= " AND p.data_pedido >= ?"; $params[] = $dt_ini; }
if (!empty($dt_fim)) { $where .= " AND p.data_pedido <= ?"; $params[] = $dt_fim; }

$filtro_emp = $_GET['filtro_emp'] ?? '';
if (!empty($filtro_emp)) { $where .= " AND p.empresa_id = ?"; $params[] = $filtro_emp; }

$filtro_obra = $_GET['filtro_obra'] ?? '';
if (!empty($filtro_obra)) { $where .= " AND p.obra_id = ?"; $params[] = $filtro_obra; }

$filtro_forn = $_GET['filtro_forn'] ?? '';
if (!empty($filtro_forn)) { $where .= " AND p.fornecedor_id = ?"; $params[] = $filtro_forn; }

$filtro_pag = $_GET['filtro_pag'] ?? '';
if (!empty($filtro_pag)) { $where .= " AND p.forma_pagamento = ?"; $params[] = $filtro_pag; }


// --- 2. FUNÇÃO AUXILIAR (STACKED) ---
function montarDatasetStacked($dados_detalhados, $eixo_principal_labels, $coluna_stack, $coluna_valor, $eixo_primario_bd) {
    $itens_stack = array_unique(array_column($dados_detalhados, $coluna_stack));
    sort($itens_stack);
    $datasets = [];
    $cores = ['#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0'];
    $i = 0;
    foreach ($itens_stack as $stack_label) {
        $data_array = [];
        foreach ($eixo_principal_labels as $label_principal) {
            $valor = 0;
            foreach ($dados_detalhados as $item) {
                if ($item[$eixo_primario_bd] == $label_principal && $item[$coluna_stack] == $stack_label) {
                    $valor = $item[$coluna_valor]; break;
                }
            }
            $data_array[] = $valor;
        }
        $datasets[] = ['label' => $stack_label, 'data' => $data_array, 'backgroundColor' => $cores[$i % count($cores)], 'stack' => 'Stack 0'];
        $i++;
    }
    return $datasets;
}

// --- 3. CONSULTAS SQL ---

// G1: MENSAL (R$)
$sql_mes = "SELECT DATE_FORMAT(p.data_pedido, '%m/%Y') as label, SUM(p.valor_bruto_pedido) as total
            FROM pedidos p JOIN obras o ON p.obra_id = o.id $where GROUP BY YEAR(p.data_pedido), MONTH(p.data_pedido) ORDER BY p.data_pedido ASC"; 
$stmt = $pdo->prepare($sql_mes); $stmt->execute($params); $dados_mes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql_mes_det = "SELECT DATE_FORMAT(p.data_pedido, '%m/%Y') as label, o.nome as item_detalhe, SUM(p.valor_bruto_pedido) as total
                FROM pedidos p JOIN obras o ON p.obra_id = o.id $where GROUP BY YEAR(p.data_pedido), MONTH(p.data_pedido), o.nome ORDER BY p.data_pedido ASC";
$stmt = $pdo->prepare($sql_mes_det); $stmt->execute($params); $raw_mes_det = $stmt->fetchAll(PDO::FETCH_ASSOC);

// G2: OBRA (RANKING)
$sql_obra = "SELECT o.nome as label, SUM(p.valor_bruto_pedido) as total
             FROM pedidos p JOIN obras o ON p.obra_id = o.id $where GROUP BY o.id ORDER BY total DESC";
$stmt = $pdo->prepare($sql_obra); $stmt->execute($params); $dados_obra = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql_obra_det = "SELECT o.nome as label, DATE_FORMAT(p.data_pedido, '%m/%Y') as item_detalhe, SUM(p.valor_bruto_pedido) as total
                 FROM pedidos p JOIN obras o ON p.obra_id = o.id $where GROUP BY o.nome, YEAR(p.data_pedido), MONTH(p.data_pedido) ORDER BY p.data_pedido ASC"; 
$stmt = $pdo->prepare($sql_obra_det); $stmt->execute($params); $raw_obra_det = $stmt->fetchAll(PDO::FETCH_ASSOC);
$altura_obra_px = max(500, count($dados_obra) * 45);

// G3: EMPREENDIMENTO
$sql_emp = "SELECT e.nome as label, SUM(p.valor_bruto_pedido) as total
            FROM pedidos p JOIN empresas e ON p.empresa_id = e.id $where GROUP BY e.id ORDER BY total DESC";
$stmt = $pdo->prepare($sql_emp); $stmt->execute($params); $dados_emp = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql_emp_det = "SELECT e.nome as label, DATE_FORMAT(p.data_pedido, '%m/%Y') as item_detalhe, SUM(p.valor_bruto_pedido) as total
                FROM pedidos p JOIN empresas e ON p.empresa_id = e.id $where GROUP BY e.nome, YEAR(p.data_pedido), MONTH(p.data_pedido) ORDER BY p.data_pedido ASC";
$stmt = $pdo->prepare($sql_emp_det); $stmt->execute($params); $raw_emp_det = $stmt->fetchAll(PDO::FETCH_ASSOC);
$altura_emp_px = max(350, count($dados_emp) * 45);

// G4: VOLUME
$sql_vol = "SELECT DATE_FORMAT(p.data_pedido, '%m/%Y') as label, COUNT(DISTINCT p.numero_of) as total
            FROM pedidos p JOIN obras o ON p.obra_id = o.id $where GROUP BY YEAR(p.data_pedido), MONTH(p.data_pedido) ORDER BY p.data_pedido ASC";
$stmt = $pdo->prepare($sql_vol); $stmt->execute($params); $dados_vol = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql_vol_det = "SELECT DATE_FORMAT(p.data_pedido, '%m/%Y') as label, o.nome as item_detalhe, COUNT(DISTINCT p.numero_of) as total
                FROM pedidos p JOIN obras o ON p.obra_id = o.id $where GROUP BY YEAR(p.data_pedido), MONTH(p.data_pedido), o.nome ORDER BY p.data_pedido ASC";
$stmt = $pdo->prepare($sql_vol_det); $stmt->execute($params); $raw_vol_det = $stmt->fetchAll(PDO::FETCH_ASSOC);

// G5: PAGAMENTO
$sql_pag = "SELECT p.forma_pagamento as label, SUM(p.valor_bruto_pedido) as total
            FROM pedidos p $where AND p.forma_pagamento != '' GROUP BY p.forma_pagamento ORDER BY total DESC";
$stmt = $pdo->prepare($sql_pag); $stmt->execute($params); $dados_pag = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql_pag_det = "SELECT p.forma_pagamento as label, DATE_FORMAT(p.data_pedido, '%m/%Y') as item_detalhe, SUM(p.valor_bruto_pedido) as total
                FROM pedidos p $where AND p.forma_pagamento != '' GROUP BY p.forma_pagamento, YEAR(p.data_pedido), MONTH(p.data_pedido) ORDER BY p.data_pedido ASC";
$stmt = $pdo->prepare($sql_pag_det); $stmt->execute($params); $raw_pag_det = $stmt->fetchAll(PDO::FETCH_ASSOC);


// --- 4. PREPARAR JSON ---
function prepSimple($data, $type='bar', $color='#0d6efd') {
    return [
        'labels' => array_column($data, 'label'),
        'datasets' => [[
            'label' => 'Total', 'data' => array_column($data, 'total'),
            'backgroundColor' => ($type=='pie'||$type=='doughnut') ? ['#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754'] : $color,
            'borderColor' => ($type=='line') ? $color : null, 'tension' => 0.3, 'fill' => ($type=='line')
        ]]
    ];
}
function prepDetailed($rawData, $baseData, $stackCol='item_detalhe') {
    $labels = array_column($baseData, 'label');
    return ['labels' => $labels, 'datasets' => montarDatasetStacked($rawData, $labels, $stackCol, 'total', 'label')];
}

$js_mes_s = prepSimple($dados_mes, 'bar', '#082c79'); $js_mes_d = prepDetailed($raw_mes_det, $dados_mes);
$js_obra_s = prepSimple($dados_obra, 'bar', '#0d6efd'); $js_obra_d = prepDetailed($raw_obra_det, $dados_obra);
$js_emp_s = prepSimple($dados_emp, 'bar', '#198754'); $js_emp_d = prepDetailed($raw_emp_det, $dados_emp);
$js_vol_s = prepSimple($dados_vol, 'bar', '#20c997'); $js_vol_d = prepDetailed($raw_vol_det, $dados_vol);
$js_pag_s = prepSimple($dados_pag, 'pie'); $js_pag_d = prepDetailed($raw_pag_det, $dados_pag);

// Listas
$obras_list = $pdo->query("SELECT id, nome FROM obras ORDER BY nome")->fetchAll();
$emp_list = $pdo->query("SELECT id, nome FROM empresas ORDER BY nome")->fetchAll();
$forn_list = $pdo->query("SELECT id, nome FROM fornecedores ORDER BY nome")->fetchAll();
$pag_list = $pdo->query("SELECT DISTINCT forma_pagamento FROM pedidos WHERE forma_pagamento != '' ORDER BY forma_pagamento")->fetchAll();
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>

<div class="container-fluid p-4 bg-light">
    
    <?php if(!isset($modo_integrado)): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h3 class="text-dark fw-bold m-0"><i class="bi bi-bar-chart-line-fill text-primary"></i> INDICADORES DE OBRAS</h3></div>
        <div>
            <button onclick="window.print()" class="btn btn-outline-danger fw-bold me-2"><i class="bi bi-file-pdf"></i> PDF</button>
            <a href="index.php?page=obras" class="btn btn-outline-dark fw-bold"><i class="bi bi-arrow-left"></i> VOLTAR</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4 border-0">
        <div class="card-body bg-white py-3">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="dashboard_obras">
                <div class="col-md-2"><label class="fw-bold small text-muted">Início</label><input type="date" name="dt_ini" class="form-control" value="<?php echo $dt_ini; ?>"></div>
                <div class="col-md-2"><label class="fw-bold small text-muted">Fim</label><input type="date" name="dt_fim" class="form-control" value="<?php echo $dt_fim; ?>"></div>
                <div class="col-md-4"><label class="fw-bold small text-muted">Obra</label><select name="filtro_obra" class="form-select"><option value="">-- Todas --</option><?php foreach($obras_list as $o): ?><option value="<?php echo $o['id']; ?>" <?php echo ($filtro_obra==$o['id'])?'selected':''; ?>><?php echo mb_strimwidth($o['nome'],0,30,'...'); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="fw-bold small text-muted">Empresa</label><select name="filtro_emp" class="form-select"><option value="">-- Todas --</option><?php foreach($emp_list as $e): ?><option value="<?php echo $e['id']; ?>" <?php echo ($filtro_emp==$e['id'])?'selected':''; ?>><?php echo mb_strimwidth($e['nome'],0,30,'...'); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="fw-bold small text-muted">Fornecedor</label><select name="filtro_forn" class="form-select"><option value="">-- Todas --</option><?php foreach($forn_list as $f): ?><option value="<?php echo $f['id']; ?>" <?php echo ($filtro_forn==$f['id'])?'selected':''; ?>><?php echo mb_strimwidth($f['nome'],0,25,'...'); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="fw-bold small text-muted">Pagamento</label><select name="filtro_pag" class="form-select"><option value="">-- Todas --</option><?php foreach($pag_list as $p): ?><option value="<?php echo $p['forma_pagamento']; ?>" <?php echo ($filtro_pag==$p['forma_pagamento'])?'selected':''; ?>><?php echo $p['forma_pagamento']; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><button class="btn btn-primary w-100 fw-bold"><i class="bi bi-funnel-fill"></i> ATUALIZAR GRÁFICOS</button></div>
            </form>
        </div>
    </div>

    <div class="card shadow mb-4 border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h5 class="m-0 fw-bold text-primary">EVOLUÇÃO MENSAL (R$) 
                    <span id="info_chartMes" class="badge bg-light text-secondary border ms-2 fw-normal" style="font-size: 0.8rem;"></span>
                </h5>
                <div class="form-check form-switch m-0 pt-1 ms-3">
                    <input class="form-check-input" type="checkbox" id="swMes" onchange="toggleChart('chartMes', 'swMes', 'containerMes')">
                    <label class="form-check-label small fw-bold text-muted" for="swMes">DETALHAR</label>
                </div>
            </div>
            <div class="d-flex gap-1 align-items-center">
                <div class="btn-group btn-group-sm me-2"><button type="button" class="btn btn-outline-secondary" onclick="mudarAltura('containerMes', -100)"><i class="bi bi-dash-lg"></i></button><button type="button" class="btn btn-outline-secondary" onclick="mudarAltura('containerMes', 100)"><i class="bi bi-plus-lg"></i></button></div>
                <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartMes', 'EVOLUÇÃO MENSAL')"><i class="bi bi-arrows-fullscreen"></i></button>
                <button class="btn btn-light btn-sm border" onclick="$('#bodyMes').slideToggle()"><i class="bi bi-eye-slash"></i></button>
            </div>
        </div>
        <div class="card-body" id="bodyMes"><div id="containerMes" style="height: 400px; transition: height 0.3s ease;"><canvas id="chartMes"></canvas></div></div>
    </div>

    <div class="card shadow mb-4 border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h5 class="m-0 fw-bold text-dark">TOTAL POR OBRA 
                    <span id="info_chartObra" class="badge bg-light text-secondary border ms-2 fw-normal" style="font-size: 0.8rem;"></span>
                </h5>
                <div class="form-check form-switch m-0 pt-1 ms-3">
                    <input class="form-check-input" type="checkbox" id="swObra" onchange="toggleChart('chartObra', 'swObra', 'containerObra')">
                    <label class="form-check-label small fw-bold text-muted" for="swObra">DETALHAR</label>
                </div>
            </div>
            <div class="d-flex gap-1 align-items-center">
                <div class="btn-group btn-group-sm me-2"><button type="button" class="btn btn-outline-secondary" onclick="mudarAltura('containerObra', -200)"><i class="bi bi-dash-lg"></i></button><button type="button" class="btn btn-outline-secondary" onclick="mudarAltura('containerObra', 200)"><i class="bi bi-plus-lg"></i></button></div>
                <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartObra', 'TOTAL POR OBRA')"><i class="bi bi-arrows-fullscreen"></i></button>
                <button class="btn btn-light btn-sm border" onclick="$('#bodyObra').slideToggle()"><i class="bi bi-eye-slash"></i></button>
            </div>
        </div>
        <div class="card-body" id="bodyObra"><div id="containerObra" style="height: <?php echo $altura_obra_px; ?>px; transition: height 0.3s ease;"><canvas id="chartObra"></canvas></div></div>
    </div>

    <div class="card shadow mb-4 border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h5 class="m-0 fw-bold text-success">VOLUME (OFs) 
                    <span id="info_chartVol" class="badge bg-light text-secondary border ms-2 fw-normal" style="font-size: 0.8rem;"></span>
                </h5>
                <div class="form-check form-switch m-0 pt-1 ms-3">
                    <input class="form-check-input" type="checkbox" id="swVol" onchange="toggleChart('chartVol', 'swVol', 'containerVol')">
                    <label class="form-check-label small fw-bold text-muted" for="swVol">DETALHAR</label>
                </div>
            </div>
            <div class="d-flex gap-1 align-items-center">
                <div class="btn-group btn-group-sm me-2"><button type="button" class="btn btn-outline-secondary" onclick="mudarAltura('containerVol', -100)"><i class="bi bi-dash-lg"></i></button><button type="button" class="btn btn-outline-secondary" onclick="mudarAltura('containerVol', 100)"><i class="bi bi-plus-lg"></i></button></div>
                <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartVol', 'VOLUME DE PEDIDOS')"><i class="bi bi-arrows-fullscreen"></i></button>
                <button class="btn btn-light btn-sm border" onclick="$('#bodyVol').slideToggle()"><i class="bi bi-eye-slash"></i></button>
            </div>
        </div>
        <div class="card-body" id="bodyVol"><div id="containerVol" style="height: 400px; transition: height 0.3s ease;"><canvas id="chartVol"></canvas></div></div>
    </div>

    <div class="card shadow mb-4 border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h5 class="m-0 fw-bold text-secondary">EMPREENDIMENTO
                    <span id="info_chartEmp" class="badge bg-light text-secondary border ms-2 fw-normal" style="font-size: 0.8rem;"></span>
                </h5>
                <div class="form-check form-switch m-0 pt-1 ms-3">
                    <input class="form-check-input" type="checkbox" id="swEmp" onchange="toggleChart('chartEmp', 'swEmp', 'containerEmp')">
                    <label class="form-check-label small fw-bold text-muted" for="swEmp">DETALHAR</label>
                </div>
            </div>
            <div class="d-flex gap-1 align-items-center">
                <div class="btn-group btn-group-sm me-2"><button type="button" class="btn btn-outline-secondary" onclick="mudarAltura('containerEmp', -200)"><i class="bi bi-dash-lg"></i></button><button type="button" class="btn btn-outline-secondary" onclick="mudarAltura('containerEmp', 200)"><i class="bi bi-plus-lg"></i></button></div>
                <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartEmp', 'RANKING EMPREENDIMENTO')"><i class="bi bi-arrows-fullscreen"></i></button>
                <button class="btn btn-light btn-sm border" onclick="$('#bodyEmp').slideToggle()"><i class="bi bi-eye-slash"></i></button>
            </div>
        </div>
        <div class="card-body" id="bodyEmp"><div id="containerEmp" style="height: <?php echo $altura_emp_px; ?>px; transition: height 0.3s ease;"><canvas id="chartEmp"></canvas></div></div>
    </div>

    <div class="card shadow mb-4 border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h5 class="m-0 fw-bold text-secondary">PAGAMENTO
                    <span id="info_chartPag" class="badge bg-light text-secondary border ms-2 fw-normal" style="font-size: 0.8rem;"></span>
                </h5>
                <button class="btn btn-outline-primary btn-sm py-0 px-2 fw-bold" onclick="togglePagamentoLabel()" title="Trocar entre % e Valor">
                    <i class="bi bi-arrow-repeat"></i> % / R$
                </button>
            </div>
            <div class="d-flex gap-3 align-items-center">
                <div class="form-check form-switch m-0 pt-1">
                    <input class="form-check-input" type="checkbox" id="swPag" onchange="toggleChart('chartPag', 'swPag', 'containerPag')">
                    <label class="form-check-label small fw-bold text-muted" for="swPag">DETALHAR</label>
                </div>
                <div class="d-flex gap-1">
                    <button class="btn btn-light btn-sm border" onclick="expandirGrafico('chartPag', 'FORMA DE PAGAMENTO')"><i class="bi bi-arrows-fullscreen"></i></button>
                    <button class="btn btn-light btn-sm border" onclick="$('#bodyPag').slideToggle()"><i class="bi bi-eye-slash"></i></button>
                </div>
            </div>
        </div>
        <div class="card-body" id="bodyPag"><div id="containerPag" style="height: 400px;"><canvas id="chartPag"></canvas></div></div>
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
const fmtBRL = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(v);
const fmtCompact = (v) => new Intl.NumberFormat('pt-BR', { notation: "compact", compactDisplay: "short" }).format(v);
try { Chart.register(ChartDataLabels); } catch(e){}

window.pagamentoEmReais = false; 

window.chartDataStore = {
    'chartMes': { type: 'bar', simple: <?php echo json_encode($js_mes_s); ?>, detailed: <?php echo json_encode($js_mes_d); ?>, options: { scales: { x: { stacked: false }, y: { stacked: false } } } },
    'chartObra': { type: 'bar', simple: <?php echo json_encode($js_obra_s); ?>, detailed: <?php echo json_encode($js_obra_d); ?>, options: { indexAxis: 'y', scales: { x: { stacked: false }, y: { stacked: false } } } },
    'chartEmp': { type: 'bar', simple: <?php echo json_encode($js_emp_s); ?>, detailed: <?php echo json_encode($js_emp_d); ?>, options: { indexAxis: 'y', scales: { x: { stacked: false }, y: { stacked: false } } } },
    'chartVol': { type: 'bar', simple: <?php echo json_encode($js_vol_s); ?>, detailed: <?php echo json_encode($js_vol_d); ?>, options: { scales: { x: { stacked: false }, y: { stacked: false } } } },
    'chartPag': { type: 'pie', simple: <?php echo json_encode($js_pag_s); ?>, detailed: <?php echo json_encode($js_pag_d); ?>, options: {} }
};

window.chartInstances = {};
const ids = ['chartMes', 'chartObra', 'chartEmp', 'chartVol', 'chartPag'];

ids.forEach(id => {
    const ctx = document.getElementById(id);
    if(ctx) {
        const store = window.chartDataStore[id];
        const baseOpts = {
            responsive: true, maintainAspectRatio: false,
            layout: { padding: { top: 25, right: 20, left: 20 } },
            plugins: {
                legend: { display: (store.type==='pie'||store.type==='doughnut'), position: 'bottom' },
                tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + (id==='chartVol' ? c.raw : fmtBRL(c.raw)) } },
                datalabels: { 
                    display: (ctx) => (ctx.chart.config.type !== 'line' && ctx.chart.data.datasets.length < 3 && ctx.dataset.data[ctx.dataIndex] > 0),
                    formatter: (value, ctx) => {
                        if (id === 'chartPag' && ctx.chart.config.type === 'pie') {
                            if (window.pagamentoEmReais) return fmtCompact(value);
                            let sum = 0; ctx.chart.data.datasets[0].data.map(d => sum+=Number(d));
                            return (sum > 0) ? (value * 100 / sum).toFixed(1) + "%" : "0%";
                        }
                        return fmtCompact(value);
                    },
                    color: (ctx) => (ctx.chart.config.type === 'pie' || ctx.chart.config.type === 'doughnut') ? '#fff' : '#444',
                    font: { weight: 'bold', size: 11 },
                    anchor: (ctx) => (ctx.chart.config.type === 'pie' || ctx.chart.config.type === 'doughnut') ? 'center' : 'end',
                    align: (ctx) => (ctx.chart.config.type === 'pie' || ctx.chart.config.type === 'doughnut') ? 'center' : 'end',
                    offset: 4
                }
            }
        };

        if(id === 'chartObra' || id === 'chartEmp') {
            baseOpts.barPercentage = 0.8; 
            baseOpts.categoryPercentage = 0.9;
            baseOpts.layout.padding.right = 40;
        }

        const finalOpts = { ...baseOpts, ...store.options };
        window.chartInstances[id] = new Chart(ctx, { type: store.type, data: store.simple, options: finalOpts });
        
        // CALCULA O TOTAL INICIAL VISUAL
        atualizarTotalVisual(id);
    }
});

// NOVA FUNÇÃO: CALCULA E EXIBE O TOTAL NO TÍTULO
function atualizarTotalVisual(chartId) {
    const chart = window.chartInstances[chartId];
    if(!chart) return;
    
    let total = 0;
    // Soma todos os datasets visíveis
    chart.data.datasets.forEach(ds => {
        ds.data.forEach(val => total += Number(val));
    });

    const el = document.getElementById('info_' + chartId);
    if(el) {
        if(chartId === 'chartVol') {
            el.innerText = total + " OFs";
        } else {
            el.innerText = fmtCompact(total);
        }
    }
}

window.togglePagamentoLabel = function() {
    window.pagamentoEmReais = !window.pagamentoEmReais;
    window.chartInstances['chartPag'].update();
}

window.mudarAltura = function(containerId, pixels) {
    const el = document.getElementById(containerId);
    if(el) {
        let h = el.offsetHeight + pixels;
        if(h < 200) h = 200; el.style.height = h + 'px';
    }
};

window.toggleChart = function(chartId, switchId, containerId) {
    const isChecked = document.getElementById(switchId).checked;
    const chart = window.chartInstances[chartId];
    const store = window.chartDataStore[chartId];
    if (!chart || !store) return;
    chart.destroy();

    let newType = store.type; let newData = store.simple; let newOptions = { ...chart.options };

    if(isChecked && containerId) {
        let el = document.getElementById(containerId);
        if(el && el.offsetHeight < 500) { el.style.height = '600px'; }
    }

    if (isChecked) {
        newType = 'bar'; newData = store.detailed;
        newOptions.scales = { x: { stacked: true }, y: { stacked: true } };
        newOptions.indexAxis = (chartId === 'chartObra' || chartId === 'chartEmp') ? 'y' : 'x';
        newOptions.plugins.legend = { display: true, position: 'top' };
    } else {
        newType = store.type; newData = store.simple;
        newOptions.scales = { 
            x: { stacked: false, display: (newType!=='pie'&&newType!=='doughnut') }, 
            y: { stacked: false, display: (newType!=='pie'&&newType!=='doughnut') } 
        };
        newOptions.indexAxis = (store.options.indexAxis || 'x');
        newOptions.plugins.legend = { display: (newType==='pie'||newType==='doughnut'), position: 'bottom' };
    }
    
    if(chartId === 'chartObra' || chartId === 'chartEmp') {
        newOptions.barPercentage = 0.8; newOptions.categoryPercentage = 0.9;
    }

    window.chartInstances[chartId] = new Chart(document.getElementById(chartId), { type: newType, data: newData, options: newOptions });
    
    // Atualiza o total ao trocar
    atualizarTotalVisual(chartId);
};

// Expandir
let modalChartInstance = null;
function expandirGrafico(chartId, titulo) {
    const sourceChart = window.chartInstances[chartId];
    if (!sourceChart) return;
    document.getElementById('modalLabel').innerText = titulo;
    const modalCanvas = document.getElementById('modalCanvas');
    if (modalChartInstance) modalChartInstance.destroy();
    
    let chartHeight = document.getElementById(chartId).parentElement.offsetHeight;
    if(chartHeight > window.innerHeight * 0.8) modalCanvas.style.height = chartHeight + "px";
    else modalCanvas.style.height = "80vh";

    modalChartInstance = new Chart(modalCanvas, {
        type: sourceChart.config.type, data: sourceChart.config.data,
        options: { ...sourceChart.config.options, maintainAspectRatio: false, plugins: { ...sourceChart.config.options.plugins, legend: { display: true, position: 'top' } } }
    });
    new bootstrap.Modal(document.getElementById('modalGrafico')).show();
}
</script>

<style>
@media print { 
    .btn, form, a, .form-check, .btn-group { display: none !important; } 
    canvas { max-width: 100% !important; height: auto !important; } 
    .card { border: none !important; box-shadow: none !important; page-break-inside: avoid; }
}
</style>