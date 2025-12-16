<?php
// GESTÃO DE OBRAS (CORRIGIDO: NOME DA COLUNA CODIGO)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- 1. CARREGAR LISTAS PARA OS FILTROS ---
$lista_fornecedores = $pdo->query("SELECT id, nome FROM fornecedores ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$lista_pagamentos = $pdo->query("SELECT DISTINCT forma_pagamento FROM pedidos WHERE forma_pagamento != '' ORDER BY forma_pagamento")->fetchAll(PDO::FETCH_ASSOC);

// --- 2. CAPTURA DE FILTROS ---
$dt_ini = $_GET['dt_ini'] ?? '';
$dt_fim = $_GET['dt_fim'] ?? '';
$filtro_forn = $_GET['filtro_forn'] ?? '';
$filtro_pag = $_GET['filtro_pag'] ?? '';
$filtro_ordem = $_GET['ordem'] ?? 'cod_desc';

// --- 3. CONSTRUÇÃO DA CONSULTA SQL ---
$where_pedidos = "WHERE 1=1";
$params = [];

if (!empty($dt_ini)) { $where_pedidos .= " AND p.data_pedido >= ?"; $params[] = $dt_ini; }
if (!empty($dt_fim)) { $where_pedidos .= " AND p.data_pedido <= ?"; $params[] = $dt_fim; }
if (!empty($filtro_forn)) { $where_pedidos .= " AND p.fornecedor_id = ?"; $params[] = $filtro_forn; }
if (!empty($filtro_pag)) { $where_pedidos .= " AND p.forma_pagamento = ?"; $params[] = $filtro_pag; }

// SQL PRINCIPAL: Agrupa pedidos por Obra
// CORREÇÃO: Trocado o.cod_obra por o.codigo
$sql = "SELECT 
            o.id, 
            o.nome, 
            o.codigo, 
            
            -- Somas financeiras (Baseado nos filtros)
            SUM(p.valor_bruto_pedido) as total_gasto,
            SUM(p.valor_total_rec) as total_executado, 
            
            COUNT(DISTINCT p.numero_of) as qtd_pedidos,
            MAX(p.data_pedido) as ultima_compra
            
        FROM obras o
        LEFT JOIN pedidos p ON p.obra_id = o.id
        $where_pedidos
        GROUP BY o.id
        HAVING total_gasto > 0 "; 

// Ordenação
switch ($filtro_ordem) {
    case 'valor_desc': $sql .= "ORDER BY total_gasto DESC"; break;
    case 'valor_asc':  $sql .= "ORDER BY total_gasto ASC"; break;
    case 'nome_asc':   $sql .= "ORDER BY o.nome ASC"; break;
    default:           $sql .= "ORDER BY o.codigo DESC"; break; // Corrigido aqui também
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $obras = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Erro SQL: " . $e->getMessage() . "</div>";
    exit;
}

// --- 4. CÁLCULO DOS TOTAIS GERAIS (KPIs) ---
$kpi_total_gasto = 0;
$kpi_total_executado = 0; 
$kpi_total_ofs = 0;

foreach($obras as $obra) {
    $kpi_total_gasto += $obra['total_gasto'];
    $kpi_total_executado += $obra['total_executado']; 
    $kpi_total_ofs += $obra['qtd_pedidos'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="m-0 text-dark fw-bold"><i class="bi bi-buildings-fill text-primary"></i> Gestão de Obras</h4>
        <small class="text-muted">Acompanhamento de custos e pedidos por obra</small>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php?page=dashboard_obras" class="btn btn-warning btn-sm shadow-sm fw-bold">
            <i class="bi bi-pie-chart-fill"></i> Dashboard
        </a>
        <a href="index.php?page=importar_mestre_xlsx" class="btn btn-dark btn-sm shadow-sm">
            <i class="bi bi-cloud-arrow-up"></i> Importar Mestre
        </a>
        <a href="index.php?page=configuracoes&tab=obras" class="btn btn-outline-primary btn-sm shadow-sm">
            <i class="bi bi-plus-lg"></i> Nova Obra
        </a>
    </div>
</div>

<div class="card shadow-sm mb-4 border-0 bg-light">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="obras">
            
            <div class="col-md-2">
                <label class="small fw-bold text-muted">Início</label>
                <input type="date" name="dt_ini" class="form-control form-control-sm" value="<?php echo $dt_ini; ?>">
            </div>
            <div class="col-md-2">
                <label class="small fw-bold text-muted">Fim</label>
                <input type="date" name="dt_fim" class="form-control form-control-sm" value="<?php echo $dt_fim; ?>">
            </div>
            
            <div class="col-md-3">
                <label class="small fw-bold text-muted">Fornecedor</label>
                <select name="filtro_forn" class="form-select form-select-sm">
                    <option value="">-- Todos --</option>
                    <?php foreach($lista_fornecedores as $f): ?>
                        <option value="<?php echo $f['id']; ?>" <?php echo ($filtro_forn == $f['id']) ? 'selected' : ''; ?>>
                            <?php echo substr($f['nome'], 0, 25); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="small fw-bold text-muted">Pagamento</label>
                <select name="filtro_pag" class="form-select form-select-sm">
                    <option value="">-- Todos --</option>
                    <?php foreach($lista_pagamentos as $p): ?>
                        <option value="<?php echo $p['forma_pagamento']; ?>" <?php echo ($filtro_pag == $p['forma_pagamento']) ? 'selected' : ''; ?>>
                            <?php echo $p['forma_pagamento']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100 fw-bold px-3">FILTRAR</button>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4 g-3">
    
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-5 border-success h-100">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-uppercase fw-bold text-success" style="font-size: 0.7rem;">TOTAL EXECUTADO (REC)</small>
                        <h4 class="fw-bold text-dark mt-1 mb-0">R$ <?php echo number_format($kpi_total_executado, 2, ',', '.'); ?></h4>
                    </div>
                    <div class="text-success opacity-25"><i class="bi bi-check-circle-fill fs-1"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-start border-5 border-primary h-100">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-uppercase fw-bold text-primary" style="font-size: 0.7rem;">VALOR TOTAL (PEDIDOS)</small>
                        <h4 class="fw-bold text-dark mt-1 mb-0">R$ <?php echo number_format($kpi_total_gasto, 2, ',', '.'); ?></h4>
                    </div>
                    <div class="text-primary opacity-25"><i class="bi bi-cash-stack fs-1"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-start border-5 border-warning h-100">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-uppercase fw-bold text-warning" style="font-size: 0.7rem;">VOLUME (QTD OFs)</small>
                        <h4 class="fw-bold text-dark mt-1 mb-0"><?php echo $kpi_total_ofs; ?></h4>
                    </div>
                    <div class="text-warning opacity-25"><i class="bi bi-file-earmark-text fs-1"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="input-group" style="max-width: 300px;">
        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
        <input type="text" id="filtroInput" class="form-control border-start-0" placeholder="Buscar obra...">
    </div>
    
    <div class="dropdown">
        <button class="btn btn-light btn-sm dropdown-toggle border" type="button" data-bs-toggle="dropdown">
            Ordenar por
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><a class="dropdown-item" href="index.php?page=obras&ordem=cod_desc">Código (Padrão)</a></li>
            <li><a class="dropdown-item" href="index.php?page=obras&ordem=valor_desc">Maior Valor Gasto</a></li>
            <li><a class="dropdown-item" href="index.php?page=obras&ordem=nome_asc">Nome (A-Z)</a></li>
        </ul>
    </div>
</div>

<div class="row" id="listaObras">
    <?php foreach($obras as $obra): 
        $textoBusca = strtolower($obra['nome'] . ' ' . $obra['codigo']);
    ?>
    <div class="col-xl-3 col-md-6 mb-4 obra-item" data-busca="<?php echo $textoBusca; ?>">
        <div class="card shadow-sm h-100 border-0 hover-effect">
            <div class="card-body d-flex flex-column p-3">
                
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge bg-dark"><?php echo $obra['codigo']; ?></span>
                    <span class="badge bg-light text-muted border"><?php echo $obra['qtd_pedidos']; ?> OFs</span>
                </div>
                
                <h6 class="card-title fw-bold text-dark text-truncate mb-3" title="<?php echo $obra['nome']; ?>">
                    <?php echo $obra['nome']; ?>
                </h6>

                <div class="mt-auto bg-light rounded p-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="text-muted" style="font-size: 10px;">PEDIDO (BRUTO)</small>
                        <span class="fw-bold text-primary small">R$ <?php echo number_format($obra['total_gasto'], 2, ',', '.'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted" style="font-size: 10px;">EXECUTADO (REC)</small>
                        <span class="fw-bold text-success small">R$ <?php echo number_format($obra['total_executado'], 2, ',', '.'); ?></span>
                    </div>
                </div>
                
                <a href="index.php?page=detalhe_obra&id=<?php echo $obra['id']; ?>" class="btn btn-outline-dark btn-sm w-100 mt-2 fw-bold stretched-link">
                    ABRIR
                </a>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if(empty($obras)): ?>
        <div class="col-12 text-center py-5">
            <h4 class="text-muted"><i class="bi bi-funnel"></i> Nenhuma obra encontrada com estes filtros.</h4>
        </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('filtroInput').addEventListener('keyup', function() {
    let termo = this.value.toLowerCase();
    let cards = document.querySelectorAll('.obra-item');
    
    cards.forEach(card => {
        let texto = card.getAttribute('data-busca');
        if(texto.includes(termo)) {
            card.style.display = ''; 
        } else {
            card.style.display = 'none'; 
        }
    });
});
</script>

<style>
    .hover-effect { transition: transform 0.2s, box-shadow 0.2s; }
    .hover-effect:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1)!important; }
</style>