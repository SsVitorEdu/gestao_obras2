<?php
// DETALHE DO CLIENTE - ATUALIZADO (COM CAMPO RESPONSÁVEL)
ini_set('display_errors', 1);
error_reporting(E_ALL);

$id_cliente = $_GET['id'] ?? 0;

if (!isset($pdo)) {
    $db_file = __DIR__ . '/../includes/db.php';
    if (file_exists($db_file)) include $db_file;
    else include __DIR__ . '/../db.php'; // Fallback
}

// --- FUNÇÃO DE AJUDA ---
function limparDinheiro($val) {
    if (!$val) return 0;
    $val = str_replace('.', '', $val); // Tira ponto de milhar
    $val = str_replace(',', '.', $val); // Troca vírgula por ponto
    return (float)$val;
}

// 2. BUSCA DADOS DO CLIENTE
$stmt = $pdo->prepare("SELECT * FROM clientes_imob WHERE id = ?");
$stmt->execute([$id_cliente]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$cliente) die("<div class='alert alert-danger'>Cliente não encontrado!</div>");

// 3. BUSCA VENDAS (CONTRATOS)
// O SELECT * já vai trazer a coluna 'responsavel' automaticamente se ela existir no banco
$stmtVendas = $pdo->prepare("SELECT * FROM vendas_imob WHERE cliente_id = ? ORDER BY data_contrato DESC");
$stmtVendas->execute([$id_cliente]);
$vendas = $stmtVendas->fetchAll(PDO::FETCH_ASSOC);

// 4. BUSCA PARCELAS
$stmtParc = $pdo->prepare("SELECT * FROM parcelas_imob WHERE venda_id IN (SELECT id FROM vendas_imob WHERE cliente_id = ?) ORDER BY data_vencimento ASC");
$stmtParc->execute([$id_cliente]);
$todas_parcelas = $stmtParc->fetchAll(PDO::FETCH_ASSOC);

// Organiza parcelas por venda
$parcelas_por_venda = [];
foreach($todas_parcelas as $p) {
    $parcelas_por_venda[$p['venda_id']][] = $p;
}
?>

<div class="container-fluid px-4 pt-3">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <span class="badge bg-secondary mb-1">CLIENTE</span>
            <h3 class="fw-bold text-dark m-0"><?php echo $cliente['nome']; ?></h3>
            <div class="text-muted small">
                <i class="bi bi-person-vcard"></i> CPF: <?php echo $cliente['cpf'] ?? '---'; ?> | 
                <i class="bi bi-telephone"></i> Tel: <?php echo $cliente['telefone'] ?? '---'; ?>
            </div>
        </div>
        <div>
            <button class="btn btn-primary fw-bold shadow-sm" onclick="novoContrato()">
                <i class="bi bi-plus-lg"></i> NOVO CONTRATO
            </button>
            <a href="index.php?page=clientes" class="btn btn-outline-secondary ms-2">Voltar</a>
        </div>
    </div>

    <?php if(isset($_GET['msg'])): ?>
        <?php if($_GET['msg']=='venda_salva'): ?>
            <div class="alert alert-success small py-2">✅ Contrato salvo com sucesso!</div>
        <?php elseif($_GET['msg']=='parcela_salva'): ?>
            <div class="alert alert-success small py-2">✅ Parcela atualizada!</div>
        <?php elseif($_GET['msg']=='venda_excluida'): ?>
            <div class="alert alert-danger small py-2">🗑️ Contrato excluído!</div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="row">
        <?php foreach($vendas as $v): 
            $p_venda = $parcelas_por_venda[$v['id']] ?? [];
            
            // Cálculos
            $total_pago = 0;
            foreach($p_venda as $pp) $total_pago += $pp['valor_pago'];
            
            $total_contrato = $v['valor_total'];
            $saldo = $total_contrato - $total_pago;
            $progresso = ($total_contrato > 0) ? ($total_pago / $total_contrato) * 100 : 0;

            // Prepara JSON para edição
            $json_venda = htmlspecialchars(json_encode($v), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="col-12 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
                    
                    <div>
                        <small class="text-muted fw-bold d-block">EMPREENDIMENTO / LOTE</small>
                        <h5 class="fw-bold text-primary m-0">
                            <?php echo $v['nome_empresa']; ?> 
                            <span class="text-dark">| <?php echo $v['nome_casa']; ?></span>
                        </h5>
                        <small class="text-secondary">Cod: <?php echo $v['codigo_compra']; ?></small>
                    </div>

                    <div class="px-3 border-start border-end">
                        <small class="text-muted fw-bold d-block">RESPONSÁVEL VENDA</small>
                        <span class="fw-bold text-dark">
                            <i class="bi bi-person-badge-fill text-secondary"></i> 
                            <?php echo !empty($v['responsavel']) ? mb_strtoupper($v['responsavel']) : '<span class="text-muted fst-italic">-- Não informado --</span>'; ?>
                        </span>
                    </div>

                    <div class="text-end">
                        <small class="text-muted fw-bold d-block">VALOR TOTAL</small>
                        <h5 class="fw-bold text-dark m-0">R$ <?php echo number_format($total_contrato, 2, ',', '.'); ?></h5>
                    </div>

                    <div class="text-end">
                        <small class="text-muted fw-bold d-block">SALDO DEVEDOR</small>
                        <h5 class="fw-bold text-danger m-0">R$ <?php echo number_format($saldo, 2, ',', '.'); ?></h5>
                    </div>

                    <div>
                        <button class="btn btn-outline-primary btn-sm" onclick="editarContrato(<?php echo $json_venda; ?>)">
                            <i class="bi bi-pencil"></i> Editar
                        </button>
                        <button class="btn btn-outline-danger btn-sm" onclick="excluirContrato(<?php echo $v['id']; ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>

                <div class="progress" style="height: 4px; border-radius: 0;">
                    <div class="progress-bar bg-success" style="width: <?php echo $progresso; ?>%"></div>
                </div>

                <div class="card-body bg-light p-2">
                    <div class="d-flex justify-content-between align-items-center mb-2 px-2">
                        <strong class="small text-muted"><i class="bi bi-list-ol"></i> PARCELAS DO CONTRATO</strong>
                        <button class="btn btn-success btn-sm py-0" style="font-size: 11px;" onclick="novaParcela(<?php echo $v['id']; ?>)">
                            + ADD PARCELA
                        </button>
                    </div>

                    <div class="table-responsive bg-white border rounded">
                        <table class="table table-sm table-hover mb-0" style="font-size: 13px;">
                            <thead class="table-light">
                                <tr>
                                    <th>Nº</th>
                                    <th>Vencimento</th>
                                    <th>Valor Parcela</th>
                                    <th>Pagamento</th>
                                    <th>Valor Pago</th>
                                    <th>Status</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($p_venda as $pp): 
                                    $status = ($pp['valor_pago'] >= $pp['valor_parcela'] - 0.01) ? 
                                        '<span class="badge bg-success">PAGO</span>' : 
                                        ((strtotime($pp['data_vencimento']) < time()) ? '<span class="badge bg-danger">VENCIDO</span>' : '<span class="badge bg-warning text-dark">ABERTO</span>');
                                    
                                    $json_parc = htmlspecialchars(json_encode($pp), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                    <td class="fw-bold text-center"><?php echo $pp['numero_parcela']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($pp['data_vencimento'])); ?></td>
                                    <td class="fw-bold">R$ <?php echo number_format($pp['valor_parcela'], 2, ',', '.'); ?></td>
                                    
                                    <td><?php echo $pp['data_pagamento'] ? date('d/m/Y', strtotime($pp['data_pagamento'])) : '-'; ?></td>
                                    <td class="<?php echo $pp['valor_pago']>0 ? 'text-success fw-bold' : 'text-muted'; ?>">
                                        <?php echo $pp['valor_pago']>0 ? 'R$ '.number_format($pp['valor_pago'], 2, ',', '.') : '-'; ?>
                                    </td>
                                    
                                    <td><?php echo $status; ?></td>
                                    
                                    <td class="text-end">
                                        <button class="btn btn-link p-0 text-primary me-2" onclick="editarParcela(<?php echo $json_parc; ?>)"><i class="bi bi-pencil-square"></i></button>
                                        <button class="btn btn-link p-0 text-danger" onclick="excluirParcela(<?php echo $pp['id']; ?>)"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($p_venda)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-3">Nenhuma parcela lançada.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if(empty($vendas)): ?>
            <div class="col-12 text-center py-5">
                <h4 class="text-muted">Este cliente ainda não tem contratos.</h4>
                <button class="btn btn-primary mt-3" onclick="novoContrato()">Criar Primeiro Contrato</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="modalVenda" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="tituloModalVenda">Contrato</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="actions/salvar_venda.php" method="POST">
                <input type="hidden" name="cliente_id" value="<?php echo $id_cliente; ?>">
                <input type="hidden" name="id" id="venda_id">
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Código Interno</label>
                            <input type="text" name="codigo_compra" id="venda_codigo" class="form-control" placeholder="Ex: A-101">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold">Empreendimento (Empresa)</label>
                            <input type="text" name="nome_empresa" id="venda_empresa" class="form-control" required placeholder="Ex: RESIDENCIAL FLORES">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-primary">Responsável Venda</label>
                            <input type="text" name="responsavel" id="venda_responsavel" class="form-control" placeholder="Quem fechou?">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Lote / Casa / Unidade</label>
                            <input type="text" name="nome_casa" id="venda_casa" class="form-control" placeholder="Ex: Lote 15 Q. 2">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Data Contrato</label>
                            <input type="date" name="data_contrato" id="venda_data" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Valor Total (R$)</label>
                            <input type="text" name="valor_total" id="venda_valor" class="form-control fw-bold" required placeholder="0,00">
                        </div>

                        <div class="col-md-12">
                            <hr class="my-2">
                            <small class="text-muted">Datas de Vigência (Opcional)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Início</label>
                            <input type="date" name="data_inicio" id="venda_ini" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Fim</label>
                            <input type="date" name="data_fim" id="venda_fim" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success fw-bold">SALVAR CONTRATO</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalParcela" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title">Lançar Parcela</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="actions/salvar_parcela.php" method="POST">
                <input type="hidden" name="cliente_id" value="<?php echo $id_cliente; ?>">
                <input type="hidden" name="venda_id" id="parc_venda_id">
                <input type="hidden" name="id" id="parc_id">

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Nº Parc</label>
                            <input type="text" name="numero_parcela" id="parc_num" class="form-control" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">Vencimento</label>
                            <input type="date" name="data_vencimento" id="parc_venc" class="form-control" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold">Valor da Parcela (R$)</label>
                            <input type="text" name="valor_parcela" id="parc_valor" class="form-control fw-bold fs-5" required placeholder="0,00">
                        </div>
                        
                        <div class="col-12"><hr></div>
                        <h6 class="text-success small fw-bold">BAIXA (PAGAMENTO)</h6>

                        <div class="col-md-6">
                            <label class="form-label small">Data Pagto</label>
                            <input type="date" name="data_pagamento" id="parc_dt_pag" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Valor Pago (R$)</label>
                            <input type="text" name="valor_pago" id="parc_vlr_pag" class="form-control text-success fw-bold">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success w-100 fw-bold">SALVAR PARCELA</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// Formatador de dinheiro para visualização
const fmtMoney = (v) => {
    if(!v) return '';
    return new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(v);
}

// -- AÇÕES CONTRATO --
function novoContrato() {
    $('#venda_id').val(''); 
    $('#venda_codigo').val('AUTO-' + Math.floor(Date.now() / 1000)); 
    $('#venda_empresa').val('');
    $('#venda_casa').val(''); 
    $('#venda_responsavel').val(''); // Limpa o campo responsável
    $('#venda_data').val(''); 
    $('#venda_valor').val('');
    $('#tituloModalVenda').text('Novo Contrato');
    new bootstrap.Modal(document.getElementById('modalVenda')).show();
}

function editarContrato(d) {
    $('#venda_id').val(d.id); 
    $('#venda_codigo').val(d.codigo_compra);
    $('#venda_empresa').val(d.nome_empresa);
    $('#venda_casa').val(d.nome_casa);
    
    // CARREGA O RESPONSÁVEL
    $('#venda_responsavel').val(d.responsavel);
    
    $('#venda_data').val(d.data_contrato);
    $('#venda_valor').val(fmtMoney(d.valor_total));
    $('#venda_ini').val(d.data_inicio);
    $('#venda_fim').val(d.data_fim);

    $('#tituloModalVenda').text('Editar Contrato');
    new bootstrap.Modal(document.getElementById('modalVenda')).show();
}

function excluirContrato(id) {
    if(confirm('ATENÇÃO: Isso apagará o contrato E TODAS AS PARCELAS dele.\nTem certeza absoluta?')) {
        window.location.href = `actions/excluir_venda.php?id=${id}&cli=<?php echo $id_cliente; ?>`;
    }
}

// -- AÇÕES PARCELA --
function novaParcela(vid) {
    $('#parc_id').val(''); 
    $('#parc_venda_id').val(vid);
    $('#parc_num').val(''); 
    $('#parc_venc').val(''); 
    $('#parc_valor').val(''); 
    $('#parc_dt_pag').val(''); 
    $('#parc_vlr_pag').val('');
    new bootstrap.Modal(document.getElementById('modalParcela')).show();
}

function editarParcela(d) {
    $('#parc_id').val(d.id);
    $('#parc_venda_id').val(d.venda_id);
    $('#parc_num').val(d.numero_parcela);
    $('#parc_venc').val(d.data_vencimento);
    $('#parc_valor').val(fmtMoney(d.valor_parcela));
    $('#parc_dt_pag').val(d.data_pagamento);
    $('#parc_vlr_pag').val(fmtMoney(d.valor_pago));
    new bootstrap.Modal(document.getElementById('modalParcela')).show();
}
function excluirParcela(id) {
    if(confirm('Excluir esta parcela?')) {
        window.location.href = `actions/excluir_parcela.php?id=${id}&cli=<?php echo $id_cliente; ?>`;
    }
}
</script>