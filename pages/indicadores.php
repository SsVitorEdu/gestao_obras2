<div class="container-fluid">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-primary"><i class="bi bi-speedometer2"></i> Painel de Indicadores Unificado</h2>
        
        <div>
            <button onclick="exportToPDF()" class="btn btn-danger btn-sm shadow-sm">
                <i class="bi bi-file-pdf"></i> Exportar PDF
            </button>
            <button onclick="exportToPPT_HD()" class="btn btn-warning btn-sm shadow-sm text-white">
                <i class="bi bi-file-earmark-slides"></i> Exportar PPT (HD com Valores)
            </button>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="indicadoresTabs" role="tablist">
                
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="obras-tab" data-bs-toggle="tab" data-bs-target="#tab-obras" type="button" role="tab">
                        <i class="bi bi-buildings-fill"></i> Obras
                    </button>
                </li>
                
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="clientes-tab" data-bs-toggle="tab" data-bs-target="#tab-clientes" type="button" role="tab">
                        <i class="bi bi-people-fill"></i> Clientes
                    </button>
                </li>
                
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="graficos-tab" data-bs-toggle="tab" data-bs-target="#tab-graficos" type="button" role="tab">
                        <i class="bi bi-pie-chart-fill"></i> Indicadores Gerais
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="gerador-tab" data-bs-toggle="tab" data-bs-target="#tab-gerador" type="button" role="tab">
                        <i class="bi bi-magic"></i> Gerador Personalizado
                    </button>
                </li>
            </ul>
        </div>

        <div class="tab-content p-4 bg-white" id="conteudoParaExportar">
            
            <div class="tab-pane fade show active" id="tab-obras" role="tabpanel">
                <?php 
                    $modo_integrado = true;
                    if(file_exists(__DIR__ . '/dashboard_obras.php')) {
                        include __DIR__ . '/dashboard_obras.php'; 
                    } else { echo "<div class='alert alert-warning'>Arquivo dashboard_obras.php não encontrado.</div>"; }
                ?>
            </div>
            
            <div class="tab-pane fade" id="tab-clientes" role="tabpanel">
                <?php 
                    $modo_integrado = true;
                    if(file_exists(__DIR__ . '/dashboard_clientes.php')) {
                        include __DIR__ . '/dashboard_clientes.php';
                    } else { echo "<div class='alert alert-warning'>Arquivo dashboard_clientes.php não encontrado.</div>"; }
                ?>
            </div>

            <div class="tab-pane fade" id="tab-graficos" role="tabpanel">
                <?php 
                    $modo_integrado = true;
                    if(file_exists(__DIR__ . '/dashboard_graficos.php')) {
                        include __DIR__ . '/dashboard_graficos.php';
                    } else { echo "<div class='alert alert-warning'>Arquivo dashboard_graficos.php não encontrado.</div>"; }
                ?>
            </div>

            <div class="tab-pane fade" id="tab-gerador" role="tabpanel">
                <?php 
                    $modo_integrado = true;
                    if(file_exists(__DIR__ . '/dashboard_gerador.php')) {
                        include __DIR__ . '/dashboard_gerador.php';
                    } else { echo "<div class='alert alert-warning'>Arquivo dashboard_gerador.php não encontrado.</div>"; }
                ?>
            </div>
            
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/gitbrent/pptxgenjs@3.12.0/dist/pptxgen.bundle.js"></script>

<script>
    // --- CORREÇÃO DE BUGS DE ABA (RESIZE DOS GRÁFICOS) ---
    document.addEventListener("DOMContentLoaded", function() {
        var tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEls.forEach(function(tabEl) {
            tabEl.addEventListener('shown.bs.tab', function (event) {
                if (typeof window.Chart !== 'undefined') {
                    Object.values(Chart.instances).forEach(c => c.resize());
                }
            });
        });
    });

    // --- PDF ---
    function exportToPDF() {
        const element = document.getElementById('conteudoParaExportar');
        const opt = {
            margin: 0.2, filename: 'Relatorio_Gestao.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'landscape' }
        };
        html2pdf().set(opt).from(element).save();
    }

    // --- POWERPOINT HD + VALORES GRANDES (SOLUÇÃO DEFINITIVA) ---
    async function exportToPPT_HD() {
        let pptx = new PptxGenJS();
        pptx.layout = 'LAYOUT_WIDE';
        
        const canvases = document.querySelectorAll('#conteudoParaExportar canvas');
        let count = 0;

        if (canvases.length === 0) {
            alert("Nenhum gráfico encontrado na tela.");
            return;
        }

        // Fator de Escala (3x = HD)
        const SCALE = 3; 

        for (let i = 0; i < canvases.length; i++) {
            const canvas = canvases[i];
            
            if (canvas.width > 0 && canvas.height > 0 && canvas.offsetParent !== null) {
                try {
                    const chart = Chart.getChart(canvas);
                    let imgData = null;

                    if (chart) {
                        // 1. SALVA ESTADO ORIGINAL (Tamanho e Fontes)
                        const originalW = canvas.width;
                        const originalH = canvas.height;
                        
                        // Garante que existe objeto de fonte
                        if(!chart.options.plugins.datalabels) chart.options.plugins.datalabels = {};
                        if(!chart.options.plugins.datalabels.font) chart.options.plugins.datalabels.font = {};
                        if(!chart.options.plugins.legend) chart.options.plugins.legend = {};
                        if(!chart.options.plugins.legend.labels) chart.options.plugins.legend.labels = {};
                        if(!chart.options.plugins.legend.labels.font) chart.options.plugins.legend.labels.font = {};

                        const oldDLSize = chart.options.plugins.datalabels.font.size || 12;
                        const oldLegSize = chart.options.plugins.legend.labels.font.size || 12;
                        const oldDisplay = chart.options.plugins.datalabels.display; // Salva se estava oculto

                        // 2. APLICA MODO GIGANTE (HD)
                        canvas.style.width = (canvas.parentElement.offsetWidth) + 'px';
                        canvas.style.height = (canvas.parentElement.offsetHeight) + 'px';
                        
                        // Redimensiona o canvas físico
                        chart.resize(originalW * SCALE, originalH * SCALE);

                        // 3. AUMENTA AS FONTES (Lupa nos números)
                        chart.options.plugins.datalabels.font.size = oldDLSize * (SCALE * 0.9); // Aumenta 3x
                        chart.options.plugins.datalabels.display = true; // FORÇA APARECER
                        chart.options.plugins.legend.labels.font.size = oldLegSize * SCALE;

                        // Atualiza Chart para desenhar grande
                        chart.update();

                        // 4. CAPTURA
                        imgData = canvas.toDataURL('image/png', 1.0);

                        // 5. RESTAURA TUDO (Para a tela voltar ao normal)
                        chart.options.plugins.datalabels.font.size = oldDLSize;
                        chart.options.plugins.datalabels.display = oldDisplay;
                        chart.options.plugins.legend.labels.font.size = oldLegSize;
                        
                        chart.resize(); // Volta ao responsivo
                        chart.update();
                    } else {
                        // Fallback
                        imgData = canvas.toDataURL('image/png');
                    }

                    // 6. GERA SLIDE
                    if(imgData) {
                        let titulo = "Gráfico";
                        let card = canvas.closest('.card');
                        if (card) {
                            let header = card.querySelector('.card-header h5, .card-header h6, .card-header .text-primary');
                            if (header) titulo = header.innerText.trim();
                        }

                        let slide = pptx.addSlide();
                        slide.addText(titulo, { x:0.5, y:0.5, w:'90%', h:0.5, fontSize:18, color:'0d6efd', bold:true, fontFace:'Arial' });
                        
                        // Adiciona imagem
                        slide.addImage({ data: imgData, x:0.5, y:1.2, w:12.3, h:5.5 });
                        count++;
                    }

                } catch (e) {
                    console.error("Erro ao exportar gráfico:", e);
                }
            }
        }

        if(count === 0) {
            alert("Nenhum gráfico visível. Abra a aba correta antes de exportar.");
        } else {
            pptx.writeFile({ fileName: "Relatorio_Gestao_HD.pptx" });
        }
    }
</script>