<?php
// DASHBOARD GERADOR V35 - CORREÇÃO DE MESES (RÉGUA DE TEMPO COMPLETA)
ini_set('display_errors', 0); 
error_reporting(E_ALL);
set_time_limit(300);

// --- API (PROCESSAMENTO) ---
if (isset($_POST['acao']) && $_POST['acao'] == 'gerar_dados') {
    header('Content-Type: application/json');
    
    try {
        // 1. Conexão
        $db_file = __DIR__ . '/../includes/db.php';
        if (file_exists($db_file)) include $db_file;
        if (!isset($pdo)) throw new Exception("Erro de conexão");
        $pdo->exec("SET NAMES utf8mb4");

        // 2. Parâmetros
        $contexto = $_POST['contexto'];
        $dimensao = $_POST['dimensao'];
        $metricas = $_POST['metricas'] ?? [];
        $dt_ini   = $_POST['dt_ini'];
        $dt_fim   = $_POST['dt_fim'];
        $rank_ini = (int)($_POST['rank_ini'] ?? 1);
        $rank_fim = (int)($_POST['rank_fim'] ?? 10);
        $filtro_pag = $_POST['filtro_pag'] ?? ''; 
        $filtro_status = $_POST['filtro_status'] ?? ''; 

        if(empty($metricas)) throw new Exception("Selecione pelo menos um valor.");

        // 3. Query Builder
        $selects = [];
        $joins   = "";
        $where   = "WHERE 1=1";
        $params  = [];
        $groupBy = "";
        $orderBy = "";
        $tabela  = "";
        $col_data = "";

        // --- CONTEXTOS ---
        if ($contexto == 'obra' || $contexto == 'fornecedor') {
            $tabela = "pedidos p";
            $col_data = "p.data_pedido";
            
            if($contexto == 'obra') {
                $joins .= " JOIN obras o ON p.obra_id = o.id "; 
            } else {
                $joins .= " LEFT JOIN obras o ON p.obra_id = o.id ";
            }
            $joins .= " LEFT JOIN fornecedores f ON p.fornecedor_id = f.id ";
            $joins .= " LEFT JOIN empresas e ON p.empresa_id = e.id ";

            if(!empty($filtro_pag)) {
                $where .= " AND p.forma_pagamento = ?";
                $params[] = $filtro_pag;
            }
        } 
        elseif ($contexto == 'cliente') {
            $tabela = "parcelas_imob p";
            $col_data = "p.data_vencimento";
            $joins .= " JOIN vendas_imob v ON p.venda_id = v.id ";
            if($filtro_status == 'pago') $where .= " AND p.valor_pago > 0 ";
            if($filtro_status == 'aberto') $where .= " AND p.valor_pago < p.valor_parcela ";
        }
        elseif ($contexto == 'contrato') {
            $tabela = "contratos c";
            $col_data = "c.data_contrato"; 
        }

        // --- FILTRO DE DATA ---
        if (!empty($dt_ini)) { $where .= " AND $col_data >= ?"; $params[] = $dt_ini; }
        if (!empty($dt_fim)) { $where .= " AND $col_data <= ?"; $params[] = $dt_fim; }

        // --- EIXO X ---
        switch ($dimensao) {
            case 'mes':
                $selects[] = "DATE_FORMAT($col_data, '%m/%Y') as label";
                $selects[] = "YEAR($col_data) as ano, MONTH($col_data) as mes";
                $groupBy   = "YEAR($col_data), MONTH($col_data)";
                $orderBy   = "ano ASC, mes ASC";
                break;
            case 'obra':
                $selects[] = "o.nome as label";
                $groupBy   = "o.id";
                $orderBy   = "total_sort DESC";
                break;
            case 'fornecedor':
                $selects[] = "COALESCE(f.nome, 'Sem Fornecedor') as label";
                $groupBy   = "f.id";
                $orderBy   = "total_sort DESC";
                break;
            case 'pagamento':
                $selects[] = "COALESCE(NULLIF(p.forma_pagamento, ''), 'Não Informado') as label";
                $groupBy   = "p.forma_pagamento";
                $orderBy   = "total_sort DESC";
                break;
            case 'empresa':
                if($contexto == 'cliente') $selects[] = "v.nome_empresa as label";
                else $selects[] = "COALESCE(e.nome, 'Sem Empresa') as label";
                $groupBy = ($contexto == 'cliente') ? "v.nome_empresa" : "p.empresa_id";
                $orderBy = "total_sort DESC";
                break;
            case 'responsavel': 
                $selects[] = "COALESCE(c.responsavel, 'N/D') as label";
                $groupBy   = "c.responsavel";
                $orderBy   = "total_sort DESC";
                break;
            case 'resumo': 
                $selects[] = "'Resumo Geral' as label";
                $groupBy   = ""; 
                $orderBy   = "";
                break;
        }

        // --- EIXO Y (VALORES) ---
        $i = 0;
        foreach ($metricas as $met) {
            $alias = "val_" . $i;
            switch ($met) {
                case 'vlr_bruto': $selects[] = "SUM(p.valor_bruto_pedido) as $alias"; break;
                case 'vlr_rec':   $selects[] = "SUM(p.valor_total_rec) as $alias"; break;
                case 'vlr_saldo': $selects[] = "SUM(p.valor_bruto_pedido - COALESCE(p.valor_total_rec,0)) as $alias"; break;
                case 'qtd_of':    $selects[] = "COUNT(DISTINCT p.numero_of) as $alias"; break;
                
                case 'cli_orig':  $selects[] = "SUM(p.valor_parcela) as $alias"; break;
                case 'cli_pago':  $selects[] = "SUM(p.valor_pago) as $alias"; break;
                case 'cli_aberto':$selects[] = "SUM(CASE WHEN p.valor_pago < p.valor_parcela THEN p.valor_parcela - p.valor_pago ELSE 0 END) as $alias"; break;
                case 'cli_venc':  $selects[] = "SUM(CASE WHEN p.data_vencimento < CURDATE() AND p.valor_pago < p.valor_parcela THEN p.valor_parcela - p.valor_pago ELSE 0 END) as $alias"; break;
                case 'con_total': $selects[] = "SUM(c.valor) as $alias"; break;
            }
            if ($i === 0 && strpos($orderBy, 'total_sort') !== false) {
                $orderBy = str_replace('total_sort', $alias, $orderBy);
            }
            $i++;
        }

        // --- LIMIT E OFFSET ---
        $limit_qtd = ($rank_fim - $rank_ini) + 1;
        $offset    = $rank_ini - 1;
        if($limit_qtd < 1) $limit_qtd = 10;
        if($offset < 0) $offset = 0;
        $sql_limit = " LIMIT $limit_qtd OFFSET $offset ";

        // --- EXECUÇÃO ---
        $sql = "SELECT " . implode(', ', $selects) . " FROM $tabela $joins $where";
        if($groupBy) $sql .= " GROUP BY $groupBy";
        if($orderBy) $sql .= " ORDER BY $orderBy";
        
        // **CORREÇÃO IMPORTANTE**: NÃO limitar se for por tempo (mes), senão corta Dezembro!
        if($dimensao != 'resumo' && $dimensao != 'mes') $sql .= $sql_limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $datasets = [];

        // --- LÓGICA DE PREENCHIMENTO DE MESES VAZIOS ---
        if ($dimensao == 'mes') {
            // 1. Indexa o que veio do banco
            $mapaDados = [];
            foreach ($dados as $row) {
                $mapaDados[$row['label']] = $row;
            }

            // 2. Prepara os datasets vazios
            foreach ($metricas as $k => $m) {
                $datasets[$k] = ['label' => getLabelMetrica($m), 'data' => []];
            }

            // 3. Loop mês a mês (Régua de tempo)
            $atual = new DateTime(!empty($dt_ini) ? $dt_ini : date('Y-01-01'));
            $atual->modify('first day of this month');
            
            $final = new DateTime(!empty($dt_fim) ? $dt_fim : date('Y-12-31'));
            $final->modify('last day of this month');

            while ($atual <= $final) {
                $lbl = $atual->format('m/Y'); // Ex: 01/2025
                $labels[] = $lbl;

                if (isset($mapaDados[$lbl])) {
                    // Tem dados nesse mês
                    foreach ($metricas as $k => $m) {
                        $datasets[$k]['data'][] = (float)$mapaDados[$lbl]['val_' . $k];
                    }
                } else {
                    // Mês vazio (preenche com 0)
                    foreach ($metricas as $k => $m) {
                        $datasets[$k]['data'][] = 0;
                    }
                }
                
                $atual->modify('+1 month');
            }

        } else {
            // Lógica Padrão (Sem preenchimento)
            foreach ($metricas as $k => $m) {
                $datasets[$k] = ['label' => getLabelMetrica($m), 'data' => []];
            }
            foreach ($dados as $row) {
                $labels[] = $row['label'];
                foreach ($metricas as $k => $m) {
                    $datasets[$k]['data'][] = (float)$row['val_' . $k];
                }
            }
        }

        echo json_encode(['status' => 'sucesso', 'labels' => $labels, 'datasets' => $datasets]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'erro', 'msg' => $e->getMessage()]);
    }
    exit;
}

function getLabelMetrica($slug) {
    $map = [
        'vlr_bruto' => 'Total Bruto', 'vlr_rec' => 'Total Pago', 'vlr_saldo' => 'Saldo', 
        'qtd_of' => 'Volume (OFs)',
        'cli_orig' => 'Total Carteira', 'cli_pago' => 'Recebido', 'cli_aberto' => 'A Receber', 'cli_venc' => 'Vencido',
        'con_total' => 'Valor Contrato'
    ];
    return $map[$slug] ?? $slug;
}

if (!isset($pdo)) {
    $db_file = __DIR__ . '/../includes/db.php';
    if (file_exists($db_file)) include $db_file;
}
$lista_pagamentos = $pdo->query("SELECT DISTINCT forma_pagamento FROM pedidos WHERE forma_pagamento != '' ORDER BY forma_pagamento")->fetchAll(PDO::FETCH_COLUMN);
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; }

    .premium-card {
        border: none; border-radius: 16px; background: #ffffff;
        box-shadow: 0 4px 20px rgba(0,0,0,0.06); transition: transform 0.2s;
    }
    .premium-card:hover { transform: translateY(-2px); }
    .premium-header {
        background: transparent; border-bottom: 1px solid rgba(0,0,0,0.04); padding: 18px 24px;
    }
    .premium-title { font-weight: 700; color: #1a202c; font-size: 1.15rem; }
    .btn-premium { border-radius: 10px; font-weight: 600; padding: 8px 16px; }
    .modal-content { border-radius: 20px; box-shadow: 0 15px 50px rgba(0,0,0,0.2); border: none; }
</style>

<div class="container-fluid p-4">
    
    <div class="card premium-card mb-4">
        <div class="card-body py-3 d-flex justify-content-between align-items-center">
            <div>
                <h3 class="m-0 premium-title" style="font-size: 1.5rem;"><i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Gerador Premium</h3>
                <small class="text-muted">Análises de alta performance.</small>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary btn-premium shadow-sm" onclick="abrirModalCriar()"><i class="bi bi-plus-lg me-1"></i> Novo</button>
                <button class="btn btn-outline-danger btn-premium" onclick="limparTudo()"><i class="bi bi-trash"></i></button>
                
                <button id="btnExportar" onclick="exportarPPT_4K()" class="btn btn-warning btn-premium text-white shadow-sm">
                    <span class="normal-text"><i class="bi bi-file-earmark-slides me-1"></i> PPT Ultra HD</span>
                    <span class="loading-text d-none"><span class="spinner-border spinner-border-sm" role="status"></span> Gerando...</span>
                </button>
            </div>
        </div>
    </div>

    <div id="area-dashboard" class="row g-4">
        <div class="col-12 text-center py-5 text-muted" id="placeholder-vazio">
            <div style="opacity: 0.5;">
                <i class="bi bi-pie-chart display-1"></i>
                <p class="fw-bold mt-3">Nenhum gráfico criado.</p>
                <button class="btn btn-sm btn-light border" onclick="abrirModalCriar()">Começar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCriar" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light border-0 p-4">
                <h5 class="modal-title fw-bold text-dark">Configurar Gráfico</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 pt-0">
                <form id="formGerador">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">1. FONTE</label>
                            <select id="inp_contexto" class="form-select fw-bold bg-light border-0" onchange="mudarContexto()">
                                <option value="obra">🚧 Obras (Pedidos)</option>
                                <option value="fornecedor">🚚 Fornecedores</option>
                                <option value="cliente">🏠 Clientes (Imob)</option>
                                <option value="contrato">📄 Contratos</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">FILTRO</label>
                            <select id="inp_filtro_extra" class="form-select bg-light border-0"><option value="">-- Todos --</option></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">PERÍODO</label>
                            <div class="input-group">
                                <input type="date" id="inp_dt_ini" class="form-control bg-light border-0">
                                <input type="date" id="inp_dt_fim" class="form-control bg-light border-0">
                            </div>
                        </div>
                        <div class="col-12"><hr class="my-2 opacity-10"></div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">2. EIXO X (AGRUPAR)</label>
                            <select id="inp_dimensao" class="form-select bg-light border-0" onchange="atualizarOpcoesExtras()"></select>
                            <div class="mt-2 p-3 bg-light rounded-3" id="box-extras" style="display:none;">
                                <div id="box-ranking" style="display:none;">
                                    <label class="small fw-bold text-primary">Ranking (Top X):</label>
                                    <div class="input-group input-group-sm mt-1">
                                        <span class="input-group-text border-0">Do</span>
                                        <input type="number" id="inp_rank_ini" class="form-control border-0" value="1">
                                        <span class="input-group-text border-0">Ao</span>
                                        <input type="number" id="inp_rank_fim" class="form-control border-0" value="10">
                                    </div>
                                </div>
                                <div id="box-porcentagem" style="display:none;" class="mt-1">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="inp_porcentagem">
                                        <label class="form-check-label small fw-bold" for="inp_porcentagem">Mostrar em %</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">3. EIXO Y (VALORES)</label>
                            <div class="card p-3 bg-light border-0" style="max-height: 160px; overflow-y: auto;" id="container_metricas"></div>
                        </div>
                        <div class="col-12"><hr class="my-2 opacity-10"></div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">VISUAL</label>
                            <select id="inp_tipo_grafico" class="form-select bg-light border-0">
                                <option value="bar">📊 Barra Vertical</option>
                                <option value="bar_h">📊 Barra Horizontal</option>
                                <option value="line">📈 Linha Suave</option>
                                <option value="pie">🍕 Pizza</option>
                                <option value="doughnut">🍩 Rosca</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-muted">COR</label>
                            <input type="color" id="inp_cor" class="form-control form-control-color w-100 border-0 shadow-sm" value="#0d6efd">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">TAMANHO</label>
                            <select id="inp_tamanho" class="form-select bg-light border-0">
                                <option value="col-md-6">Metade (1/2)</option>
                                <option value="col-md-12">Tela Cheia</option>
                                <option value="col-md-4">Pequeno (1/3)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">TÍTULO</label>
                            <input type="text" id="inp_titulo" class="form-control bg-light border-0 fw-bold" placeholder="Digite o título...">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-light text-muted fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary fw-bold px-4" onclick="criarGrafico()">CRIAR</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
<script src="https://cdn.jsdelivr.net/gh/gitbrent/pptxgenjs@3.12.0/dist/pptxgen.bundle.js"></script>

<script>
Chart.register(ChartDataLabels);
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#4a5568';

const pagamentos = <?php echo json_encode($lista_pagamentos); ?>;
const opcoes = {
    obra: { dim: [{v:'mes',t:'📅 EVOLUÇÃO'},{v:'obra',t:'🏗️ OBRA'},{v:'empresa',t:'🏢 EMPRESA'},{v:'pagamento',t:'💳 PAGAMENTO'}], met: [{v:'vlr_bruto',t:'Total Bruto'},{v:'qtd_of',t:'Qtd OFs'},{v:'vlr_rec',t:'Pago'},{v:'vlr_saldo',t:'Saldo'}], filtro: pagamentos },
    fornecedor: { dim: [{v:'resumo',t:'📑 RESUMO'},{v:'pagamento',t:'💳 PAGAMENTO'},{v:'fornecedor',t:'🏆 RANKING'},{v:'mes',t:'📅 TEMPO'}], met: [{v:'vlr_bruto',t:'Consumido'},{v:'vlr_rec',t:'Pago'},{v:'vlr_saldo',t:'Saldo'}], filtro: pagamentos },
    cliente: { dim: [{v:'mes',t:'📅 FLUXO'},{v:'empresa',t:'🏢 EMPRESA'}], met: [{v:'cli_pago',t:'Recebido'},{v:'cli_aberto',t:'A Receber'},{v:'cli_orig',t:'Carteira'},{v:'cli_venc',t:'Vencido'}], filtro: ['pago', 'aberto'] },
    contrato: { dim: [{v:'responsavel',t:'👤 RESPONSÁVEL'},{v:'mes',t:'📅 EVOLUÇÃO'}], met: [{v:'con_total',t:'Valor Contrato'}], filtro: [] }
};

const STORAGE_KEY = 'gestao_obras_v29';

$(document).ready(function() { restaurarDashboard(); });

function limparDatas() { $('#inp_dt_ini').val(''); $('#inp_dt_fim').val(''); }
function abrirModalCriar() { mudarContexto(); $('#inp_titulo').val(''); limparDatas(); $('#inp_porcentagem').prop('checked', false); $('#inp_cor').val('#0d6efd'); new bootstrap.Modal(document.getElementById('modalCriar')).show(); }

function mudarContexto() {
    const ctx = $('#inp_contexto').val();
    const dados = opcoes[ctx];
    $('#inp_dimensao').empty(); dados.dim.forEach(d => $('#inp_dimensao').append(`<option value="${d.v}">${d.t}</option>`));
    $('#container_metricas').empty(); dados.met.forEach(m => $('#container_metricas').append(`<div class="form-check mb-2"><input class="form-check-input chk-metrica" type="checkbox" value="${m.v}" id="chk_${m.v}"><label class="form-check-label small" for="chk_${m.v}">${m.t}</label></div>`));
    $('.chk-metrica').first().prop('checked', true);
    $('#inp_filtro_extra').empty().append('<option value="">-- Todos --</option>');
    if (ctx === 'obra' || ctx === 'fornecedor') dados.filtro.forEach(p => $('#inp_filtro_extra').append(`<option value="${p}">Forma: ${p}</option>`));
    else if (ctx === 'cliente') { $('#inp_filtro_extra').append('<option value="pago">Só Pagos</option><option value="aberto">Só Abertos</option>'); }
    atualizarOpcoesExtras();
}

function atualizarOpcoesExtras() {
    const dim = $('#inp_dimensao').val();
    let showExtras = false;
    if(['obra', 'fornecedor', 'empresa', 'responsavel'].includes(dim)) { $('#box-ranking').show(); showExtras = true; } else { $('#box-ranking').hide(); }
    if(dim === 'pagamento') { $('#box-porcentagem').show(); showExtras = true; } else { $('#box-porcentagem').hide(); }
    if(showExtras) $('#box-extras').slideDown(); else $('#box-extras').slideUp();
}

function criarGrafico() {
    const metricas = [];
    $('.chk-metrica:checked').each(function(){ metricas.push($(this).val()); });
    if(metricas.length === 0) { alert('Selecione um valor.'); return; }

    const config = {
        id: Date.now(),
        contexto: $('#inp_contexto').val(), dimensao: $('#inp_dimensao').val(), metricas: metricas,
        filtro_pag: $('#inp_filtro_extra').val(), filtro_status: $('#inp_filtro_extra').val(),
        dt_ini: $('#inp_dt_ini').val(), dt_fim: $('#inp_dt_fim').val(),
        rank_ini: $('#inp_rank_ini').val(), rank_fim: $('#inp_rank_fim').val(),
        grafico: $('#inp_tipo_grafico').val(), tamanho: $('#inp_tamanho').val(),
        cor_base: $('#inp_cor').val(), titulo: $('#inp_titulo').val() || 'Gráfico',
        em_porcentagem: $('#inp_porcentagem').is(':checked') && $('#inp_dimensao').val() === 'pagamento'
    };
    salvarConfiguracao(config); solicitarDadosERenderizar(config); $('#modalCriar').modal('hide'); $('#placeholder-vazio').hide();
}

function solicitarDadosERenderizar(config) {
    $.post('pages/dashboard_gerador.php', { acao: 'gerar_dados', ...config }, function(resp){
        if(resp.status === 'erro') console.error("Erro:", resp.msg); else renderizarCard(config, resp);
    }, 'json');
}

function hexToRgb(hex) {
    var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result ? { r: parseInt(result[1], 16), g: parseInt(result[2], 16), b: parseInt(result[3], 16) } : null;
}

function generatePalette(baseColor, count) {
    let rgb = hexToRgb(baseColor);
    if (!rgb) rgb = {r:13, g:110, b:253}; // Fallback
    let palette = [baseColor];
    for (let i = 1; i < count; i++) {
        let factor = i * 0.15;
        let nr = Math.min(255, rgb.r + (255 - rgb.r) * factor);
        let ng = Math.min(255, rgb.g + (255 - rgb.g) * factor);
        let nb = Math.min(255, rgb.b + (255 - rgb.b) * factor);
        palette.push(`rgb(${nr}, ${ng}, ${nb})`);
    }
    return palette;
}

function renderizarCard(config, dados) {
    const canvasId = 'genChart_' + config.id;
    $('#area-dashboard').append(`
        <div class="${config.tamanho} chart-wrapper" id="card_${config.id}">
            <div class="card premium-card h-100">
                <div class="card-header premium-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0 premium-title text-truncate" title="${config.titulo}">${config.titulo}</h5>
                    <button class="btn btn-light btn-sm rounded-circle p-2" onclick="deletarGrafico(${config.id})"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="card-body"><div style="height: 350px;"><canvas id="${canvasId}"></canvas></div></div>
            </div>
        </div>
    `);
    const ctx = document.getElementById(canvasId);
    
    let baseColors = [];
    if (config.grafico === 'pie' || config.grafico === 'doughnut') {
        baseColors = generatePalette(config.cor_base, dados.labels.length);
    } else {
        baseColors = generatePalette(config.cor_base, config.metricas.length);
    }

    const datasets = dados.datasets.map((ds, i) => {
        const isPie = (config.grafico === 'pie' || config.grafico === 'doughnut');
        const finalColorHex = isPie ? baseColors : baseColors[i % baseColors.length];
        
        let bgColors, borderColors;
        if(Array.isArray(finalColorHex)) {
             bgColors = finalColorHex.map(c => { let rgb = hexToRgb(c) || {r:0,g:0,b:0}; return `rgba(${rgb.r},${rgb.g},${rgb.b},0.85)`; });
             borderColors = finalColorHex;
        } else {
             let rgb = hexToRgb(finalColorHex) || {r:0,g:0,b:0};
             bgColors = `rgba(${rgb.r},${rgb.g},${rgb.b},0.85)`;
             borderColors = finalColorHex;
        }

        return {
            label: ds.label, data: ds.data,
            backgroundColor: bgColors, borderColor: borderColors, borderWidth: 1,
            borderRadius: isPie ? 0 : 6, fill: (config.grafico === 'line'), tension: 0.3,
            clip: false
        };
    });
    
    let idxAxis = 'x'; if(config.grafico === 'bar_h') { config.grafico = 'bar'; idxAxis = 'y'; }
    let anchorPos = 'end'; let alignPos = 'top';
    if(idxAxis === 'y') { anchorPos = 'end'; alignPos = 'end'; }
    if (config.grafico === 'pie' || config.grafico === 'doughnut') { anchorPos = 'center'; alignPos = 'center'; }

    new Chart(ctx, {
        type: config.grafico,
        data: { labels: dados.labels, datasets: datasets },
        plugins: [ChartDataLabels],
        options: {
            indexAxis: idxAxis, responsive: true, maintainAspectRatio: false,
            layout: { padding: { top: 30, right: 30, left: 10, bottom: 10 } },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, font: {size: 11, weight:'600'} } },
                datalabels: {
                    display: true,
                    formatter: (v, ctx) => {
                        if(v === 0 || v === null) return ""; 
                        if(config.em_porcentagem) {
                            let sum = 0; ctx.dataset.data.map(d => sum += d);
                            return (sum > 0) ? (v*100/sum).toFixed(1) + "%" : "";
                        }
                        return new Intl.NumberFormat('pt-BR', { notation: "compact", compactDisplay: "short" }).format(v);
                    },
                    color: '#2c3e50', anchor: anchorPos, align: alignPos,
                    font: {weight:'800', size: 12}, offset: 4,
                    textShadowBlur: 4, textShadowColor: '#ffffff'
                }
            },
            scales: {
                x: { display: !['pie','doughnut'].includes(config.grafico), grid: { display: false }, ticks: { font: {weight:'600'} } },
                y: { display: !['pie','doughnut'].includes(config.grafico), beginAtZero: true, grid: { borderDash: [5,5], color: 'rgba(0,0,0,0.05)' } }
            }
        }
    });
}

function salvarConfiguracao(c) { let l = JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]'); l.push(c); localStorage.setItem(STORAGE_KEY, JSON.stringify(l)); }
function deletarGrafico(id) { if(confirm('Remover?')) { $('#card_'+id).remove(); let l=JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]'); localStorage.setItem(STORAGE_KEY, JSON.stringify(l.filter(c=>c.id!==id))); if(l.length<=1)$('#placeholder-vazio').show(); } }
function limparTudo() { if(confirm('Limpar?')) { $('.chart-wrapper').remove(); localStorage.removeItem(STORAGE_KEY); $('#placeholder-vazio').show(); } }
function restaurarDashboard() { let l = JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]'); if(l.length>0) { $('#placeholder-vazio').hide(); l.forEach(c => solicitarDadosERenderizar(c)); } }

// --- EXPORTAR PPT ULTRA HD (4K + 500MS WAIT) ---
async function exportarPPT_4K() {
    $('#btnExportar .normal-text').addClass('d-none');
    $('#btnExportar .loading-text').removeClass('d-none');
    
    let pptx = new PptxGenJS(); pptx.layout = 'LAYOUT_WIDE';
    const canvases = document.querySelectorAll('.chart-wrapper canvas');
    
    if(canvases.length === 0) { 
        alert("Crie gráficos primeiro!"); 
        $('#btnExportar .normal-text').removeClass('d-none');
        $('#btnExportar .loading-text').addClass('d-none');
        return; 
    }

    const SCALE = 4; // Resolução 4X (Ultra HD)

    for (let i = 0; i < canvases.length; i++) {
        const canvas = canvases[i];
        if (canvas.width > 0 && canvas.height > 0) {
            try {
                const chart = Chart.getChart(canvas);
                let imgData = null;

                if (chart) {
                    const origW = canvas.width; const origH = canvas.height;
                    const oldSize = chart.options.plugins.datalabels.font.size || 12;
                    const oldLeg = chart.options.plugins.legend.labels.font.size || 11;
                    const oldAnim = chart.options.animation; 

                    // 1. Desativa Animação
                    chart.options.animation = false;

                    // 2. Escala 4x
                    canvas.style.width = (canvas.parentElement.offsetWidth) + 'px';
                    canvas.style.height = (canvas.parentElement.offsetHeight) + 'px';
                    chart.resize(origW * SCALE, origH * SCALE);

                    // 3. Zoom Fontes (Proporcional para 4x)
                    // Multiplicador 1.0 mantém a proporção exata visual.
                    chart.options.plugins.datalabels.font.size = oldSize * SCALE;
                    chart.options.plugins.legend.labels.font.size = oldLeg * SCALE;
                    
                    chart.update(); 
                    
                    // 4. Pausa de Render (500ms para evitar slide branco)
                    await new Promise(r => setTimeout(r, 500));

                    // 5. Captura
                    imgData = canvas.toDataURL('image/png', 1.0);

                    // 6. Restaura
                    chart.options.animation = oldAnim;
                    chart.options.plugins.datalabels.font.size = oldSize;
                    chart.options.plugins.legend.labels.font.size = oldLeg;
                    chart.resize(); 
                    chart.update();
                } else { imgData = canvas.toDataURL('image/png'); }

                if (imgData) {
                    let t = canvas.closest('.card').querySelector('h5').innerText;
                    let s = pptx.addSlide();
                    s.addText(t, {x:0.5,y:0.5,w:'90%',fontSize:24,color:'1a202c',bold:true, fontFace:'Arial'});
                    s.addImage({data:imgData,x:0.5,y:1.3,w:12.3,h:5.8});
                }
            } catch (e) { console.error(e); }
        }
    }
    
    pptx.writeFile({ fileName: "Relatorio_Premium_UltraHD.pptx" });
    
    $('#btnExportar .normal-text').removeClass('d-none');
    $('#btnExportar .loading-text').addClass('d-none');
}
</script>