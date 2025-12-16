<?php
// DASHBOARD GRÁFICOS (V5 - ANTI-TRAVAMENTO)
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

// --- 1. CONEXÃO ---
if (!isset($pdo)) {
    $db_file = __DIR__ . '/../includes/db.php';
    if (file_exists($db_file)) include $db_file;
}
// Garante UTF-8 para não quebrar JSON
$pdo->exec("SET NAMES utf8mb4");

$where_pedidos = "WHERE 1=1";
$params_pedidos = [];
$where_contratos = "WHERE 1=1"; 
$params_contratos = [];

$dt_ini = $_GET['dt_ini'] ?? date('Y-01-01');
$dt_fim = $_GET['dt_fim'] ?? date('Y-12-31');

// Filtro Data (Só Pedidos)
if (!empty($dt_ini)) { $where_pedidos .= " AND p.data_pedido >= ?"; $params_pedidos[] = $dt_ini; }
if (!empty($dt_fim)) { $where_pedidos .= " AND p.data_pedido <= ?"; $params_pedidos[] = $dt_fim; }

// Filtros Gerais
$filtro_emp = $_GET['filtro_emp'] ?? '';
if (!empty($filtro_emp)) { $where_pedidos .= " AND p.empresa_id = ?"; $params_pedidos[] = $filtro_emp; }

$filtro_obra = $_GET['filtro_obra'] ?? '';
if (!empty($filtro_obra)) { 
    $where_pedidos .= " AND p.obra_id = ?"; $params_pedidos[] = $filtro_obra;
    $where_contratos .= " AND c.fornecedor_id IN (SELECT DISTINCT fornecedor_id FROM pedidos WHERE obra_id = ?)";
    $params_contratos[] = $filtro_obra;
}

$filtro_forn = $_GET['filtro_forn'] ?? '';
if (!empty($filtro_forn)) { 
    $where_pedidos .= " AND p.fornecedor_id = ?"; $params_pedidos[] = $filtro_forn;
    $where_contratos .= " AND c.fornecedor_id = ?"; $params_contratos[] = $filtro_forn;
}

$filtro_pag = $_GET['filtro_pag'] ?? '';
if (!empty($filtro_pag)) { $where_pedidos .= " AND p.forma_pagamento = ?"; $params_pedidos[] = $filtro_pag; }


// --- 2. FUNÇÃO AUXILIAR ---
if (!function_exists('montarDatasetStacked')) {
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
                    // Comparação frouxa (==) para evitar erro de tipo string/int
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
}

// --- 3. DADOS SQL ---

// G1: RESUMO FINANCEIRO
$stmt = $pdo->prepare("SELECT SUM(p.valor_bruto_pedido) as bruto, SUM(p.valor_total_rec) as executado FROM pedidos p $where_pedidos");
$stmt->execute($params_pedidos); $fin = $stmt->fetch(PDO::FETCH_ASSOC);
$dados_fin_s = [['label' => 'VALOR BRUTO', 'total' => $fin['bruto']??0], ['label' => 'TOTAL EXECUTADO', 'total' => $fin['executado']??0], ['label' => 'SALDO A EXECUTAR', 'total' => ($fin['bruto']??0) - ($fin['executado']??0)]];

$sql_fin_det = "SELECT 'VALOR BRUTO' as label, f.nome as item_detalhe, SUM(p.valor_bruto_pedido) as total FROM pedidos p JOIN fornecedores f ON p.fornecedor_id = f.id $where_pedidos GROUP BY f.nome
    UNION ALL SELECT 'TOTAL EXECUTADO' as label, f.nome as item_detalhe, SUM(p.valor_total_rec) as total FROM pedidos p JOIN fornecedores f ON p.fornecedor_id = f.id $where_pedidos GROUP BY f.nome
    UNION ALL SELECT 'SALDO A EXECUTAR' as label, f.nome as item_detalhe, SUM(p.valor_bruto_pedido - p.valor_total_rec) as total FROM pedidos p JOIN fornecedores f ON p.fornecedor_id = f.id $where_pedidos GROUP BY f.nome";
$stmt = $pdo->prepare($sql_fin_det); $stmt->execute(array_merge($params_pedidos, $params_pedidos, $params_pedidos)); $raw_fin_det = $stmt->fetchAll(PDO::FETCH_ASSOC);

// G2: PAGAMENTO (PIZZA)
$stmt = $pdo->prepare("SELECT forma_pagamento as label, SUM(valor_bruto_pedido) as total FROM pedidos p $where_pedidos AND p.forma_pagamento != '' GROUP BY forma_pagamento ORDER BY total DESC");
$stmt->execute($params_pedidos); $dados_pag = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT p.forma_pagamento as label, f.nome as item_detalhe, SUM(p.valor_bruto_pedido) as total FROM pedidos p JOIN fornecedores f ON p.fornecedor_id = f.id $where_pedidos AND p.forma_pagamento != '' GROUP BY p.forma_pagamento, f.nome");
$stmt->execute($params_pedidos); $raw_pag_det = $stmt->fetchAll(PDO::FETCH_ASSOC);

// G3: CONTRATOS (ROBUSTO)
try {
    $check = $pdo->query("SHOW TABLES LIKE 'contratos'");
    if($check->rowCount() > 0) {
        $stmt = $pdo->prepare("SELECT c.responsavel as label, SUM(c.valor) as total FROM contratos c $where_contratos GROUP BY c.responsavel ORDER BY total DESC");
        $stmt->execute($params_contratos); $dados_resp = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT c.responsavel as label, COALESCE(f.nome, 'Sem Fornecedor') as item_detalhe, SUM(c.valor) as total 
                               FROM contratos c LEFT JOIN fornecedores f ON c.fornecedor_id = f.id $where_contratos 
                               GROUP BY c.responsavel, f.nome ORDER BY total DESC");
        $stmt->execute($params_contratos); $raw_resp_det = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else { $dados_resp=[]; $raw_resp_det=[]; }
} catch (Exception $e) { $dados_resp=[]; $raw_resp_det=[]; }

// G4: RANKING FORNECEDORES
$stmt = $pdo->prepare("SELECT f.nome as label, SUM(p.valor_bruto_pedido) as total FROM pedidos p JOIN fornecedores f ON p.fornecedor_id = f.id $where_pedidos GROUP BY f.id ORDER BY total DESC"); 
$stmt->execute($params_pedidos); $dados_forn = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT f.nome as label, DATE_FORMAT(p.data_pedido, '%m/%Y') as item_detalhe, SUM(p.valor_bruto_pedido) as total FROM pedidos p JOIN fornecedores f ON p.fornecedor_id = f.id $where_pedidos GROUP BY f.nome, YEAR(p.data_pedido), MONTH(p.data_pedido) ORDER BY p.data_pedido ASC");
$stmt->execute($params_pedidos); $raw_forn_det = $stmt->fetchAll(PDO::FETCH_ASSOC);
$altura_forn_px = max(500, count($dados_forn) * 45);

// --- 4. PREPARAR JSON (COM CORES PARA PIZZA) ---
if (!function_exists('prepSimpleG')) { 
    function prepSimpleG($data, $colors=null) {
        // Se colors for null e for pizza, gera cores
        if(!$colors) $colors = '#0d6efd';
        return ['labels' => array_column($data, 'label'), 'datasets' => [['label' => 'Total', 'data' => array_column($data, 'total'), 'backgroundColor' => $colors, 'borderRadius' => 4]]]; 
    } 
}
if (!function_exists('prepDetailedG')) { function prepDetailedG($rawData, $baseData) { return ['labels' => array_column($baseData, 'label'), 'datasets' => montarDatasetStacked($rawData, array_column($baseData, 'label'), 'item_detalhe', 'total', 'label')]; } }

// G1: Resumo
$js_fin_s = prepSimpleG($dados_fin_s, ['#0d6efd', '#198754', '#dc3545']); 
$js_fin_d = prepDetailedG($raw_fin_det, $dados_fin_s);

// G2: Pagamento (AGORA TEM ARRAY DE CORES PARA NÃO TRAVAR)
$cores_pizza = ['#6610f2', '#d63384', '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0', '#0d6efd'];
$js_pag_s = prepSimpleG($dados_pag, $cores_pizza); 
$js_pag_d = prepDetailedG($raw_pag_det, $dados_pag);

// G3: Contratos
$js_resp_s = prepSimpleG($dados_resp, '#ffc107'); 
$js_resp_d = prepDetailedG($raw_resp_det, $dados_resp);

// G4: Fornecedores
$js_forn_s = prepSimpleG($dados_forn, '#0d6efd'); 
$js_forn_d = prepDetailedG($raw_forn_det, $dados_forn);

// Listas
$emp_list = $pdo->query("SELECT id, nome FROM empresas ORDER BY nome")->fetchAll();
$obras_list = $pdo->query("SELECT id, nome FROM obras ORDER BY nome")->fetchAll();
$forn_list = $pdo->query("SELECT id, nome FROM fornecedores ORDER BY nome")->fetchAll();
$pag_list = $pdo->query("SELECT DISTINCT forma_pagamento FROM pedidos WHERE forma_pagamento != '' ORDER BY forma_pagamento")->fetchAll();
?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>

<div class="container-fluid p-4 bg-light">
    
    <div class="card shadow-sm mb-4 border-primary">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="dashboard_graficos">
                <div class="col-md-2"><label class="fw-bold small text-muted">Início</label><input type="date" name="dt_ini" class="form-control" value="<?php echo $dt_ini; ?>"></div>
                <div class="col-md-2"><label class="fw-bold small text-muted">Fim</label><input type="date" name="dt_fim" class="form-control" value="<?php echo $dt_fim; ?>"></div>
                <div class="col-md-4"><label class="fw-bold small text-muted">Empresa</label><select name="filtro_emp" class="form-select"><option value="">-- Todas --</option><?php foreach($emp_list as $e): ?><option value="<?php echo $e['id']; ?>" <?php echo ($filtro_emp==$e['id'])?'selected':''; ?>><?php echo substr($e['nome'],0,35); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="fw-bold small text-muted">Obra</label><select name="filtro_obra" class="form-select"><option value="">-- Todas --</option><?php foreach($obras_list as $o): ?><option value="<?php echo $o['id']; ?>" <?php echo ($filtro_obra==$o['id'])?'selected':''; ?>><?php echo substr($o['nome'],0,35); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="fw-bold small text-muted">Fornecedor</label><select name="filtro_forn" class="form-select"><option value="">-- Todas --</option><?php foreach($forn_list as $f): ?><option value="<?php echo $f['id']; ?>" <?php echo ($filtro_forn==$f['id'])?'selected':''; ?>><?php echo substr($f['nome'],0,30); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="fw-bold small text-muted">Pagamento</label><select name="filtro_pag" class="form-select"><option value="">-- Todas --</option><?php foreach($pag_list as $p): ?><option value="<?php echo $p['forma_pagamento']; ?>" <?php echo ($filtro_pag==$p['forma_pagamento'])?'selected':''; ?>><?php echo $p['forma_pagamento']; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-5"><button class="btn btn-primary fw-bold w-100"><i class="bi bi-funnel"></i> ATUALIZAR DADOS</button></div>
            </form>
        </div>
    </div>

    <div class="card shadow border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-dark m-0">RESUMO FINANCEIRO <span id="info_chartFin_G" class="badge bg-light text-secondary border ms-2 fw-normal" style="font-size: 0.8rem;"></span></h5>
            <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch m-0 pt-1 me-3"><input class="form-check-input" type="checkbox" id="swFin_G" onchange="toggleChart_G('chartFin_G', 'swFin_G', 'containerFin_G')"><label class="form-check-label small fw-bold text-muted">DETALHAR</label></div>
                <button class="btn btn-light btn-sm border" onclick="expandirGrafico_G('chartFin_G', 'RESUMO FINANCEIRO')"><i class="bi bi-arrows-fullscreen"></i></button>
                <button class="btn btn-light btn-sm border" onclick="$('#bodyFin_G').slideToggle()"><i class="bi bi-eye-slash"></i></button>
            </div>
        </div>
        <div class="card-body" id="bodyFin_G"><div id="containerFin_G" style="height: 400px; transition: height 0.3s ease;"><canvas id="chartFin_G"></canvas></div></div>
    </div>

    <div class="card shadow border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-dark m-0">FORMA DE PAGAMENTO (R$) <span id="info_chartPag_G" class="badge bg-light text-secondary border ms-2 fw-normal" style="font-size: 0.8rem;"></span></h5>
            <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch m-0 pt-1 me-3"><input class="form-check-input" type="checkbox" id="swPag_G" onchange="toggleChart_G('chartPag_G', 'swPag_G', 'containerPag_G')"><label class="form-check-label small fw-bold text-muted">DETALHAR</label></div>
                <button class="btn btn-light btn-sm border" onclick="expandirGrafico_G('chartPag_G', 'FORMA DE PAGAMENTO')"><i class="bi bi-arrows-fullscreen"></i></button>
                <button class="btn btn-light btn-sm border" onclick="$('#bodyPag_G').slideToggle()"><i class="bi bi-eye-slash"></i></button>
            </div>
        </div>
        <div class="card-body" id="bodyPag_G"><div id="containerPag_G" style="height: 400px;"><canvas id="chartPag_G"></canvas></div></div>
    </div>

    <div class="card shadow border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
             <h5 class="fw-bold text-dark m-0">CONTRATOS POR RESPONSÁVEL <span id="info_chartResp_G" class="badge bg-light text-secondary border ms-2 fw-normal" style="font-size: 0.8rem;"></span></h5>
             <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch m-0 pt-1 me-3"><input class="form-check-input" type="checkbox" id="swResp_G" onchange="toggleChart_G('chartResp_G', 'swResp_G', 'containerResp_G')"><label class="form-check-label small fw-bold text-muted">POR FORNECEDOR</label></div>
                <button class="btn btn-light btn-sm border" onclick="expandirGrafico_G('chartResp_G', 'CONTRATOS POR RESPONSÁVEL')"><i class="bi bi-arrows-fullscreen"></i></button>
                <button class="btn btn-light btn-sm border" onclick="$('#bodyResp_G').slideToggle()"><i class="bi bi-eye-slash"></i></button>
            </div>
        </div>
        <div class="card-body" id="bodyResp_G"><div id="containerResp_G" style="height: 400px;"><canvas id="chartResp_G"></canvas></div></div>
    </div>

    <div class="card shadow border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-dark m-0">RANKING DE GASTO <span id="info_chartForn_G" class="badge bg-light text-secondary border ms-2 fw-normal" style="font-size: 0.8rem;"></span></h5>
            <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch m-0 pt-1 me-3"><input class="form-check-input" type="checkbox" id="swForn_G" onchange="toggleChart_G('chartForn_G', 'swForn_G', 'containerForn_G')"><label class="form-check-label small fw-bold text-muted">POR MÊS</label></div>
                <div class="btn-group btn-group-sm me-1"><button type="button" class="btn btn-outline-secondary" onclick="mudarAltura_G('containerForn_G', -200)"><i class="bi bi-dash-lg"></i></button><button type="button" class="btn btn-outline-secondary" onclick="mudarAltura_G('containerForn_G', 200)"><i class="bi bi-plus-lg"></i></button></div>
                <button class="btn btn-light btn-sm border" onclick="expandirGrafico_G('chartForn_G', 'RANKING FORNECEDORES')"><i class="bi bi-arrows-fullscreen"></i></button>
                <button class="btn btn-light btn-sm border" onclick="$('#bodyForn_G').slideToggle()"><i class="bi bi-eye-slash"></i></button>
            </div>
        </div>
        <div class="card-body" id="bodyForn_G"><div id="containerForn_G" style="height: <?php echo $altura_forn_px; ?>px; transition: height 0.3s ease;"><canvas id="chartForn_G"></canvas></div></div>
    </div>
</div>

<div class="modal fade" id="modalGrafico_G" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-dark text-white py-2"><h5 class="modal-title fw-bold" id="modalLabel_G"></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-white" style="height: 80vh;"><canvas id="modalCanvas_G"></canvas></div></div></div>
</div>

<script>
{
    const fmtBRL_G = (val) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(val);
    const fmtCompact_G = (val) => new Intl.NumberFormat('pt-BR', { notation: "compact", compactDisplay: "short", maximumFractionDigits: 1 }).format(val);

    try { Chart.register(ChartDataLabels); } catch(e){}
    Chart.defaults.font.family = "'Segoe UI', sans-serif";

    window.chartDataStore_G = {
        'chartFin_G': { type: 'bar', simple: <?php echo json_encode($js_fin_s, JSON_UNESCAPED_UNICODE); ?>, detailed: <?php echo json_encode($js_fin_d, JSON_UNESCAPED_UNICODE); ?>, options: { scales: { x: { stacked: false }, y: { stacked: false } } } },
        'chartPag_G': { type: 'pie', simple: <?php echo json_encode($js_pag_s, JSON_UNESCAPED_UNICODE); ?>, detailed: <?php echo json_encode($js_pag_d, JSON_UNESCAPED_UNICODE); ?>, options: { } },
        'chartResp_G': { type: 'bar', simple: <?php echo json_encode($js_resp_s, JSON_UNESCAPED_UNICODE); ?>, detailed: <?php echo json_encode($js_resp_d, JSON_UNESCAPED_UNICODE); ?>, options: { scales: { x: { stacked: false }, y: { stacked: false } } } },
        'chartForn_G': { type: 'bar', simple: <?php echo json_encode($js_forn_s, JSON_UNESCAPED_UNICODE); ?>, detailed: <?php echo json_encode($js_forn_d, JSON_UNESCAPED_UNICODE); ?>, options: { indexAxis: 'y', scales: { x: { stacked: false }, y: { stacked: false } } } }
    };

    window.chartInstances_G = {};
    const ids_G = ['chartFin_G', 'chartPag_G', 'chartResp_G', 'chartForn_G'];

    ids_G.forEach(id => {
        const ctx = document.getElementById(id);
        if(ctx) {
            try {
                const store = window.chartDataStore_G[id];
                // Verifica se tem dados para evitar crash
                if(!store.simple || !store.simple.labels) return;

                const baseOpts = {
                    responsive: true, maintainAspectRatio: false,
                    layout: { padding: { top: 25, right: 20 } },
                    plugins: {
                        legend: { display: (store.type==='pie'||store.type==='doughnut'), position: 'bottom' },
                        tooltip: { callbacks: { label: (c) => fmtBRL_G(c.raw) } },
                        datalabels: {
                            display: (ctx) => (ctx.chart.data.datasets.length < 3 && ctx.dataset.data[ctx.dataIndex] > 0),
                            anchor: (ctx) => (store.type==='pie') ? 'center' : 'end',
                            align: (ctx) => (store.type==='pie') ? 'center' : 'end',
                            formatter: (val) => fmtCompact_G(val),
                            color: (ctx) => (store.type==='pie') ? '#fff' : '#444',
                            font: { weight: 'bold' },
                            offset: 4
                        }
                    },
                    scales: { y: { beginAtZero: true, grid: { color: '#f0f0f0' } }, x: { grid: { display: false } } }
                };

                // Ajustes específicos
                if(id === 'chartForn_G') {
                    baseOpts.indexAxis = 'y'; baseOpts.barPercentage = 0.8; baseOpts.scales = { x: { display: false }, y: { display: true } };
                }
                if(store.type === 'pie') {
                    baseOpts.scales = { x: { display: false }, y: { display: false } };
                }

                const finalOpts = { ...baseOpts, ...store.options };
                window.chartInstances_G[id] = new Chart(ctx, { type: store.type, data: store.simple, options: finalOpts });
                atualizarTotalVisual_G(id);
            } catch(error) {
                console.error("Erro ao criar gráfico " + id, error);
            }
        }
    });

    window.atualizarTotalVisual_G = function(chartId) {
        if(!window.chartInstances_G[chartId]) return;
        let total = 0;
        const chart = window.chartInstances_G[chartId];
        // Resumo: Pega só dataset[0] se simples
        if(chartId === 'chartFin_G' && !document.getElementById('swFin_G').checked) {
             total = Number(chart.data.datasets[0].data[0]); 
        } else {
            chart.data.datasets.forEach(ds => { ds.data.forEach(val => total += Number(val)); });
        }
        const el = document.getElementById('info_' + chartId);
        if(el) el.innerText = fmtCompact_G(total);
    };

    window.mudarAltura_G = function(containerId, pixels) {
        const el = document.getElementById(containerId);
        if(el) { let h = el.offsetHeight + pixels; if(h < 200) h = 200; el.style.height = h + 'px'; }
    };

    window.toggleChart_G = function(chartId, switchId, containerId) {
        try {
            const isChecked = document.getElementById(switchId).checked;
            const chart = window.chartInstances_G[chartId];
            const store = window.chartDataStore_G[chartId];
            if (!chart || !store) return;
            chart.destroy();

            let newType = store.type; let newData = store.simple; let newOptions = { ...chart.options };
            if(isChecked && containerId) { let el = document.getElementById(containerId); if(el && el.offsetHeight < 500) { el.style.height = '600px'; } }
            
            if (isChecked) { 
                newType = 'bar'; newData = store.detailed; 
                newOptions.scales = { x: { stacked: true }, y: { stacked: true } }; 
                newOptions.plugins.legend = { display: true, position: 'top' }; 
            } else { 
                newType = store.type; newData = store.simple; 
                if(newType !== 'pie') { newOptions.scales = { x: { stacked: false }, y: { stacked: false } }; newOptions.plugins.legend = { display: false }; }
            }
            
            if(chartId === 'chartForn_G') { newOptions.indexAxis = 'y'; }
            if(newType === 'pie') { newOptions.scales = { x: { display: false }, y: { display: false } }; }

            window.chartInstances_G[chartId] = new Chart(document.getElementById(chartId), { type: newType, data: newData, options: newOptions });
            atualizarTotalVisual_G(chartId);
        } catch(e) { console.error(e); }
    };

    let modalChartInstance_G = null;
    window.expandirGrafico_G = function(chartId, titulo) {
        const sourceChart = window.chartInstances_G[chartId];
        if (!sourceChart) return;
        document.getElementById('modalLabel_G').innerText = titulo;
        const modalCanvas = document.getElementById('modalCanvas_G');
        if (modalChartInstance_G) modalChartInstance_G.destroy();
        let chartHeight = document.getElementById(chartId).parentElement.offsetHeight;
        if(chartHeight > window.innerHeight * 0.8) modalCanvas.style.height = chartHeight + "px"; else modalCanvas.style.height = "80vh";
        modalChartInstance_G = new Chart(modalCanvas, { type: sourceChart.config.type, data: sourceChart.config.data, options: { ...sourceChart.config.options, maintainAspectRatio: false, plugins: { ...sourceChart.config.options.plugins, legend: { display: true, position: 'top' } } } });
        new bootstrap.Modal(document.getElementById('modalGrafico_G')).show();
    };
}
</script>

<style>
@media print { .btn, form, a, .form-check, .btn-group { display: none !important; } .card { border: none !important; box-shadow: none !important; page-break-inside: avoid; } canvas { max-width: 100% !important; height: auto !important; } }
</style>