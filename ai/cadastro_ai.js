function escapeHtml(s){ 
  return (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); 
}

function stripTags(input) {
  return (input || '').replace(/<\/?[^>]+(>|$)/g, ""); 
}

$.ajaxSetup({
  xhrFields: {
    withCredentials: true
  }
});
function checkQueueSystem() {
    return $.getJSON('ai/list_products.php?per_page=1&user_id=0')
        .then(function(resp) {
            return resp.ok && resp.queue_table_exists !== false;
        })
        .catch(function() {
            return false;
        });
}

// ========== SISTEMA PRINCIPAL DE CADASTRO AI ==========
$(function(){
  let categoriasDoProduto = []; // Armazena a lista de categorias

  // Função que retorna uma Promise: ela avisa quando as categorias forem carregadas.
  function carregarCategorias() {
    return $.ajax({
      url: 'ai/get_categoria.php',
      dataType: 'json',
      success: function(data) {
        categoriasDoProduto = data;
        console.log('Categorias carregadas com sucesso:', data);
      },
      error: function(jqXHR, textStatus, errorThrown) {
        console.error('Falha ao carregar as categorias:', textStatus, errorThrown);
        console.log('Resposta do servidor:', jqXHR.responseText);
        categoriasDoProduto = []; 
      }
    });
  }

  const categoriasPromise = carregarCategorias();

  // Evento para carregar dimensões da categoria
  $(document).on('change', '#default_categoria_id', function() {
    const id = $(this).val();
    if (!id) return;

    $.getJSON('ai/get_categoria_dimensoes.php?id=' + id, function(resp) {
        if (resp.ok && resp.dimensoes) {
            $('#default_altura').val(resp.dimensoes.default_altura || '');
            $('#default_largura').val(resp.dimensoes.default_largura || '');
            $('#default_comprimento').val(resp.dimensoes.default_comprimento || '');
            $('#default_peso').val(resp.dimensoes.default_peso || '');
            $('#default_valor_inicial').val(resp.dimensoes.default_valor_inicial || '');
        }
    }).fail(function() {
        console.error('Erro ao carregar dimensões da categoria');
    });
  });

  // Botão para abrir modal de dimensões
  $(document).on('click', '#btnEditarDimensoes', function() {
    const categoriaId = $('#default_categoria_id').val();
    if (!categoriaId) {
      alert('Selecione uma categoria primeiro');
      return;
    }

    // Preencher o modal com os valores atuais
    $('#dimCategoriaId').val(categoriaId);
    $('#formDimensoesCategoria input[name="default_altura"]').val($('#default_altura').val());
    $('#formDimensoesCategoria input[name="default_largura"]').val($('#default_largura').val());
    $('#formDimensoesCategoria input[name="default_comprimento"]').val($('#default_comprimento').val());
    $('#formDimensoesCategoria input[name="default_peso"]').val($('#default_peso').val());
    $('#formDimensoesCategoria input[name="default_valor_inicial"]').val($('#default_valor_inicial').val());

    // Abrir o modal
    $('#modalDimensoesCategoria').modal('show');
  });

  // Botão para salvar as dimensões
  $(document).on('click', '#btnSalvarDimensoes', function() {
    const formData = new FormData($('#formDimensoesCategoria')[0]);

    $.ajax({
      url: 'ai/salvar_dimensoes_categoria.php',
      method: 'POST',
      data: formData,
      processData: false,
      contentType: false,
      dataType: 'json',
      success: function(resp) {
        if (resp.ok) {
          alert('Dimensões salvas com sucesso!');
          $('#modalDimensoesCategoria').modal('hide');
          // Atualizar os campos no formulário principal com os novos valores?
          // Opcional: recarregar as dimensões da categoria para garantir que estão sincronizadas
          const id = $('#default_categoria_id').val();
          $.getJSON('ai/get_categoria_dimensoes.php?id=' + id, function(resp) {
            if (resp.ok && resp.dimensoes) {
                $('#default_altura').val(resp.dimensoes.default_altura || '');
                $('#default_largura').val(resp.dimensoes.default_largura || '');
                $('#default_comprimento').val(resp.dimensoes.default_comprimento || '');
                $('#default_peso').val(resp.dimensoes.default_peso || '');
                $('#default_valor_inicial').val(resp.dimensoes.default_valor_inicial || '');
            }
          });
        } else {
          alert('Erro: ' + resp.msg);
        }
      },
      error: function() {
        alert('Erro de comunicação ao salvar dimensões');
      }
    });
  });

  // Botão de enviar AI
  $('#btnEnviarAI').on('click', function(){
    const fd = new FormData();
    
    // Capturando TODOS os dados do lote, incluindo o novo campo de valor
    fd.append('id_dono', $('#id_dono_ai').val() || '');
    fd.append('estoque', $('#estoque_ai').val() || 'sim');
    fd.append('frete', $('#frete_ai').val() || 'nao');
    fd.append('status_produto', $('#status_produto_ai').val() || 'ativo');
    fd.append('recomendar', $('#recomendar_ai').val() || 'nao');
    fd.append('default_categoria_id', $('#default_categoria_id').val() || '');
    fd.append('default_valor_inicial', $('#default_valor_inicial').val() || '');
    fd.append('default_altura', $('#default_altura').val() || '');
    fd.append('default_largura', $('#default_largura').val() || '');
    fd.append('default_comprimento', $('#default_comprimento').val() || '');
    fd.append('default_peso', $('#default_peso').val() || '');

    const files = $('#arquivosAI')[0].files;
    if (!files || files.length === 0) {
      alert('Por favor, selecione as imagens dos produtos.');
      return;
    }
    for (let i=0; i<files.length; i++){ 
        fd.append('arquivos[]', files[i]); 
    }

    $('#ai-lote-definicoes').hide();
    $('#ai-lote-processamento').show();
    $(this).prop('disabled', true);
    $('#aiJobStatus').html('Enviando arquivos...');
    
    $.ajax({
      url: 'processar_ai.php', 
      method: 'POST', 
      data: fd,
      contentType: false, 
      processData: false, 
      dataType: 'json',
      success: function(resp){
        if(resp && resp.ok){
          $('#aiJobStatus').html(`<strong>Job #${resp.job_id} criado.</strong> Processando imagens... <i class="fa fa-spinner fa-spin"></i>`);
          pollStatus(resp.job_id);
        } else {
          $('#aiJobStatus').text('Erro: ' + (resp.msg || 'Falha ao criar job')).removeClass('alert-info').addClass('alert-danger');
          $('#btnEnviarAI').prop('disabled', false);
        }
      },
      error: function(){ 
        $('#aiJobStatus').text('Erro de comunicação durante o upload.').removeClass('alert-info').addClass('alert-danger'); 
        $('#btnEnviarAI').prop('disabled', false);
      }
    });
  });

  function pollStatus(jobId){
    const i = setInterval(function(){
      $.get('processar_ai.php?action=status&job_id=' + jobId, function(data){
        if(!data || !data.ok){ 
          $('#aiJobStatus').text('Erro ao consultar status: ' + (data.msg || '')).removeClass('alert-info').addClass('alert-danger'); 
          clearInterval(i); 
          return; 
        }
        
        let statusText = `<strong>Status:</strong> ${data.status} | <strong>Imagens:</strong> ${data.total_images} | <strong>Produtos Sugeridos:</strong> ${data.total_products}`;
        if (['processing', 'queued'].includes(data.status)) { 
            statusText += ' <i class="fa fa-spinner fa-spin"></i>'; 
        }
        $('#aiJobStatus').html(statusText);
        
        if(['done', 'error'].includes(data.status)){
          clearInterval(i);
          if(data.status === 'error'){
              $('#aiJobStatus').text('Ocorreu um erro no processamento da AI.').removeClass('alert-info').addClass('alert-danger');
          } else {
              $('#aiJobStatus').text('Processamento concluído! Revise os produtos abaixo.').removeClass('alert-info').addClass('alert-success');
              
              categoriasPromise.done(function() {
                $('#aiJobPreview').html(renderPreview(data.products || []));
              });
          }
        }
      }).fail(function() {
        $('#aiJobStatus').text('Erro de comunicação ao verificar status.').removeClass('alert-info').addClass('alert-danger'); 
        clearInterval(i);
      });
    }, 5000);
  }

  function renderPreview(products){
    if(!products.length) return '<div class="alert alert-warning">Nenhum produto foi identificado.</div>';

    let html = '<form id="formImportAI" method="POST" action="importar_ai.php" target="_blank">';
    html += `<div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light border rounded"><div><input type="checkbox" id="selecionarTodosAI" checked> <label for="selecionarTodosAI" class="ml-2 mb-0">Selecionar Todos</label></div><button type="submit" class="btn btn-success"><i class="fa fa-upload"></i> Importar</button></div>`;

    products.forEach(function(p){
      let categoryOptions = '';
      if (categoriasDoProduto.length > 0) {
          categoryOptions = categoriasDoProduto.map(cat => {
              const isSelected = p.categoria_id != null && String(cat.id) == String(p.categoria_id);
              return `<option value="${cat.id}" ${isSelected ? 'selected' : ''}>${escapeHtml(cat.text)}</option>`;
          }).join('');
      } else {
          categoryOptions = '<option value="">Nenhuma categoria encontrada</option>';
      }

      html += `<div class="card mb-3"><div class="card-body"><div class="row">
            <div class="col-md-3">
              ${p.imagem_capa ? `<img src="ai/get_staging_image.php?path=${encodeURIComponent(p.imagem_capa)}" class="img-fluid rounded" alt="Prévia">` : ''}
            </div>
            <div class="col-md-9">
              <div class="d-flex align-items-center mb-2">
                <input type="checkbox" class="form-check-input product-checkbox" name="product_ids[]" value="${p.id}" checked style="transform: scale(1.5);">
                <strong class="ml-3">Produto #${p.id}</strong>
                <span class="ml-auto badge badge-${p.requires_review ? 'warning' : 'success'}">${p.requires_review ? 'Revisão' : 'OK'}</span>
                <span class="ml-2 text-muted small">Confiança: ${Number(p.confidence||0).toFixed(0)}%</span>
              </div>
              <div class="form-group mb-2"><label class="small">Nome</label><input name="nome_item[${p.id}]" class="form-control form-control-sm" value="${escapeHtml(p.nome_item||'')}"></div>
              <div class="form-group mb-2"><label class="small">Descrição</label><textarea name="descricao[${p.id}]" class="form-control form-control-sm" rows="3">${escapeHtml(p.descricao||'')}</textarea></div>
              <div class="form-row">
                <div class="col-md-8 form-group">
                  <label class="small">Categoria</label>
                  <select name="categoria[${p.id}]" class="form-control form-control-sm select-categoria">${categoryOptions}</select>
                </div>
                <div class="col-md-4 form-group"><label class="small">Valor (R$)</label><input name="valor_inicial[${p.id}]" class="form-control form-control-sm" value="${p.valor_inicial||'1.00'}"></div>
              </div>
              <div class="form-row">
                  <div class="col-md-3 form-group"><label class="small">Altura (cm)</label><input name="altura[${p.id}]" class="form-control form-control-sm" value="${p.altura_cm||''}"></div>
                  <div class="col-md-3 form-group"><label class="small">Largura (cm)</label><input name="largura[${p.id}]" class="form-control form-control-sm" value="${p.largura_cm||''}"></div>
                  <div class="col-md-3 form-group"><label class="small">Comprimento (cm)</label><input name="comprimento[${p.id}]" class="form-control form-control-sm" value="${p.comprimento_cm||''}"></div>
                  <div class="col-md-3 form-group"><label class="small">Peso (kg)</label><input name="peso[${p.id}]" class="form-control form-control-sm" value="${p.peso_kg||''}"></div>
              </div>
            </div></div></div></div>`;
    });

    html += '<div class="text-right mt-3"><button type="submit" class="btn btn-success"><i class="fa fa-upload"></i> Importar</button></div></form>';
    
    setTimeout(() => {  
        $('.select-categoria').not('.select2-hidden-accessible').select2({  
            theme: 'bootstrap4',  
            placeholder: 'Selecione uma categoria',  
            width: '100%'  
        });  
    }, 100);

    return html;
  }

  $(document).on('change', '#selecionarTodosAI', function() {
    $('.product-checkbox').prop('checked', $(this).prop('checked'));
  });

});

// ========== SISTEMA DE FILA PARA PRODUTOS EXISTENTES ==========

// Variável global para armazenar a fila
let processingQueue = [];

// Função para carregar produtos existentes
function carregarProdutosExistentes(pagina) {
    const q = $('#searchProdutosExistentes').val() || '';
    const perPage = $('#perPageProdutosExistentes').val() || 12;
    
    let userId = $('#id_dono_ai').val();
    if (!userId || userId === '0') {
        // Tentar obter da sessão ou usar fallback seguro
        userId = '1011'; // Seu ID como fallback
    }

    console.log('Carregando produtos com user_id:', userId); // Debug

    $('#listaProdutosExistentes').html('<div class="p-3 text-center"><i class="fa fa-spinner fa-spin"></i> Carregando...</div>');

    $.getJSON(`ai/list_products.php?q=${encodeURIComponent(q)}&page=${pagina}&per_page=${perPage}&user_id=${userId}`, function(resp) {
        if (!resp.ok) {
            $('#listaProdutosExistentes').html(`<div class="alert alert-danger">Erro ao carregar produtos: ${resp.msg || 'Erro desconhecido'}</div>`);
            return;
        }

        let html = '';
        if (resp.items.length === 0) {
            html = '<div class="alert alert-info">Nenhum produto encontrado.</div>';
        } else {
            html += `
                <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light border rounded">
                    <div>
                        <span class="badge badge-primary">${resp.total} produtos encontrados</span>
                        <small class="text-muted ml-2">(user_id: ${resp.user_id_used})</small>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-info mr-2" onclick="carregarFila()">
                            <i class="fa fa-shopping-cart"></i> Ver Fila (${processingQueue.length})
                        </button>
                        <button class="btn btn-sm btn-success" onclick="processarFila()" ${processingQueue.length === 0 ? 'disabled' : ''}>
                            <i class="fa fa-bolt"></i> Processar Fila
                        </button>
                    </div>
                </div>
            `;
            
            resp.items.forEach(p => {
                const inQueue = p.queue_status === 'pending';
                const btnClass = inQueue ? 'btn-warning' : 'btn-primary';
                const btnText = inQueue ? 'Na Fila &#10003;' : 'Add à Fila';
                const btnIcon = inQueue ? 'fa-check' : 'fa-plus';
                
                html += `
                    <div class="card mb-2 pe-card" data-product-id="${p.id}">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-2">
                                    <img src="${p.imagem}" class="pe-thumb rounded" style="width: 80px; height: 80px; object-fit: cover;" onerror="this.src='/img/carregando_foto.jpg'">
                                </div>
                                <div class="col-6">
                                    <strong class="d-block">${escapeHtml(p.nome_item)}</strong>
                                    <small class="text-muted d-block">${escapeHtml(stripTags(p.descricao).substring(0,120))}...</small>
                                    <span class="badge badge-secondary">${p.categoria_nome || 'Sem categoria'}</span>
                                </div>
                                <div class="col-4 text-right">
                                    <button class="btn btn-sm ${btnClass} btn-toggle-queue" 
                                            data-product-id="${p.id}"
                                            data-in-queue="${inQueue}">
                                        <i class="fa ${btnIcon}"></i> ${btnText}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>`;
            });

            // Paginação
            if (resp.total > perPage) {
                const totalPages = Math.ceil(resp.total / perPage);
                html += `<div class="paginas mt-3">`;
                if (pagina > 1) {
                    html += `<a href="javascript:void(0)" onclick="carregarProdutosExistentes(${pagina - 1})">&lt;&lt;</a>`;
                }
                
                // Mostrar apenas algumas páginas ao redor da atual
                const startPage = Math.max(1, pagina - 2);
                const endPage = Math.min(totalPages, pagina + 2);
                
                for (let i = startPage; i <= endPage; i++) {
                    html += `<a href="javascript:void(0)" onclick="carregarProdutosExistentes(${i})" 
                              class="${i == pagina ? 'ativo1' : ''}">${i}</a>`;
                }
                
                if (pagina < totalPages) {
                    html += `<a href="javascript:void(0)" onclick="carregarProdutosExistentes(${pagina + 1})">&gt;&gt;</a>`;
                }
                html += `</div>`;
            }
        }

        $('#listaProdutosExistentes').html(html);
        
    }).fail(() => {
        $('#listaProdutosExistentes').html('<div class="alert alert-danger">Erro na requisição.</div>');
    });
}

function toggleQueue(productId, inQueue, button) {
    // Obter user_id de forma confiável
    let userId = $('#id_dono_ai').val();
    if (!userId || userId === '0') {
        userId = '1011';
    }
    
    // Mostrar loading no botão
    const originalHtml = button.html();
    button.html('<i class="fa fa-spinner fa-spin"></i> Processando...').prop('disabled', true);
    
    if (inQueue) {
        // Remover da fila
        $.post('ai/queue_manager.php', {
            action: 'remove_from_queue',
            product_id: productId,
            user_id: userId
        }, function(resp) {
            button.prop('disabled', false);
            if (resp.ok) {
                button.removeClass('btn-warning').addClass('btn-primary')
                      .html('<i class="fa fa-plus"></i> Add à Fila')
                      .data('in-queue', false);
                processingQueue = processingQueue.filter(id => id !== productId);
                updateQueueBadge();
            } else {
                button.html(originalHtml);
                alert('Erro: ' + resp.msg);
            }
        }).fail(function(xhr, status, error) {
            button.prop('disabled', false).html(originalHtml);
            alert('Erro de comunicação com o servidor: ' + error);
        });
    } else {
        // Adicionar à fila
        $.post('ai/queue_manager.php', {
            action: 'add_to_queue',
            product_id: productId,
            user_id: userId
        }, function(resp) {
            button.prop('disabled', false);
            if (resp.ok) {
                button.removeClass('btn-primary').addClass('btn-warning')
                      .html('<i class="fa fa-check"></i> Na Fila &#10003;')
                      .data('in-queue', true);
                processingQueue.push(productId);
                updateQueueBadge();
            } else {
                button.html(originalHtml);
                alert('Erro: ' + resp.msg);
            }
        }).fail(function(xhr, status, error) {
            button.prop('disabled', false).html(originalHtml);
            alert('Erro de comunicação com o servidor: ' + error);
        });
    }
}

// Função para carregar e exibir a fila atual
function carregarFila() {
    $.post('ai/queue_manager.php', { action: 'get_queue' }, function(resp) {
        if (resp.ok) {
            let html = `
                <div class="modal fade" id="modalFilaProcessamento">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Fila de Processamento AI</h5>
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                            </div>
                            <div class="modal-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="badge badge-info">${resp.queue.length} produtos na fila</span>
                                    <div>
                                        <button class="btn btn-sm btn-outline-danger mr-2" onclick="limparFila()">
                                            <i class="fa fa-trash"></i> Limpar Fila
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick="processarFila()" ${resp.queue.length === 0 ? 'disabled' : ''}>
                                            <i class="fa fa-bolt"></i> Processar Fila
                                        </button>
                                    </div>
                                </div>
            `;
            
            if (resp.queue.length === 0) {
                html += '<div class="alert alert-info text-center">Nenhum produto na fila de processamento.</div>';
            } else {
                resp.queue.forEach(item => {
                    html += `
                        <div class="card mb-2 queue-item">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-2">
                                        <img src="${item.imagem_url}" class="rounded" style="width: 60px; height: 60px; object-fit: cover;" onerror="this.src='/img/carregando_foto.jpg'">
                                    </div>
                                    <div class="col-8">
                                        <strong class="d-block">${escapeHtml(item.nome_item)}</strong>
                                        <small class="text-muted">${item.categoria_nome || 'Sem categoria'}</small>
                                        <br>
                                        <small class="text-info">Adicionado em: ${new Date(item.added_at).toLocaleString('pt-BR')}</small>
                                    </div>
                                    <div class="col-2 text-right">
                                        <button class="btn btn-sm btn-outline-danger" onclick="removerItemFila(${item.product_id})" title="Remover da fila">
                                            <i class="fa fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            html += `
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Fechar</button>
                                ${resp.queue.length > 0 ? `
                                <button class="btn btn-success" onclick="processarFila()">
                                    <i class="fa fa-bolt"></i> Processar ${resp.queue.length} Produtos
                                </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove modal existente se houver
            $('#modalFilaProcessamento').remove();
            $('body').append(html);
            $('#modalFilaProcessamento').modal('show');
            
        } else {
            alert('Erro ao carregar fila: ' + resp.msg);
        }
    });
}

// Função para processar a fila
function processarFila() {
    if (processingQueue.length === 0) {
        alert('Nenhum produto na fila para processar');
        return;
    }
    
    // Fechar modais abertos
    $('#modalProdutosExistentes').modal('hide');
    $('#modalFilaProcessamento').modal('hide');
    
    // Mostrar status de processamento
    $('#ai-lote-definicoes').hide();
    $('#ai-lote-processamento').show();
    $('#aiJobStatus').html(`<div class="alert alert-info"><strong>Preparando processamento...</strong> ${processingQueue.length} produtos na fila <i class="fa fa-spinner fa-spin"></i></div>`);
    
    // Usar o mesmo endpoint de processamento existente
    $.post('ai/process_existing.php', { product_ids: processingQueue }, function(resp) {
        if (resp.ok) {
            $('#aiJobStatus').html(`<strong>Job #${resp.job_id} criado.</strong> Processando ${processingQueue.length} produtos... <i class="fa fa-spinner fa-spin"></i>`);
            pollStatusAI(resp.job_id);
            
            // Limpar a fila local
            processingQueue = [];
            updateQueueBadge();
        } else {
            $('#aiJobStatus').html(`<div class="alert alert-danger">Erro: ${resp.msg || 'Falha ao processar fila'}</div>`);
        }
    }, 'json').fail(() => {
        $('#aiJobStatus').html('<div class="alert alert-danger">Erro de comunicação ao processar fila</div>');
    });
}

// Função de pollStatus para produtos existentes (separada da principal)
function pollStatusAI(jobId){
    const i = setInterval(function(){
        $.get('processar_ai.php?action=status&job_id=' + jobId, function(data){
            if(!data || !data.ok){ 
                $('#aiJobStatus').text('Erro ao consultar status: ' + (data.msg || '')).removeClass('alert-info').addClass('alert-danger'); 
                clearInterval(i); 
                return; 
            }
            
            let statusText = `<strong>Status:</strong> ${data.status} | <strong>Imagens:</strong> ${data.total_images} | <strong>Produtos Sugeridos:</strong> ${data.total_products}`;
            if (['processing', 'queued'].includes(data.status)) { 
                statusText += ' <i class="fa fa-spinner fa-spin"></i>'; 
            }
            $('#aiJobStatus').html(statusText);
            
            if(['done', 'error'].includes(data.status)){
                clearInterval(i);
                if(data.status === 'error'){
                    $('#aiJobStatus').text('Ocorreu um erro no processamento da AI.').removeClass('alert-info').addClass('alert-danger');
                } else {
                    $('#aiJobStatus').text('Processamento concluído! Revise os produtos abaixo.').removeClass('alert-info').addClass('alert-success');
                    
                    // Recarregar categorias para o preview
                    $.getJSON('ai/get_categoria.php', function(categorias) {
                        $('#aiJobPreview').html(renderPreview(data.products || [], categorias));
                    });
                }
            }
        }).fail(function() {
            $('#aiJobStatus').text('Erro de comunicação ao verificar status.').removeClass('alert-info').addClass('alert-danger'); 
            clearInterval(i);
        });
    }, 5000);
}

// Função para limpar a fila
function limparFila() {
    if (!confirm('Tem certeza que deseja limpar toda a fila? Todos os produtos selecionados serão removidos.')) return;
    
    $.post('ai/queue_manager.php', { action: 'clear_queue' }, function(resp) {
        if (resp.ok) {
            processingQueue = [];
            updateQueueBadge();
            $('#modalFilaProcessamento').modal('hide');
            carregarProdutosExistentes(1);
            alert('Fila limpa com sucesso!');
        } else {
            alert('Erro: ' + resp.msg);
        }
    });
}

// Função para remover item individual da fila
function removerItemFila(productId) {
    $.post('ai/queue_manager.php', {
        action: 'remove_from_queue',
        product_id: productId
    }, function(resp) {
        if (resp.ok) {
            processingQueue = processingQueue.filter(id => id !== productId);
            updateQueueBadge();
            
            if ($('#modalFilaProcessamento').is(':visible')) {
                carregarFila();
            }
            
            carregarProdutosExistentes(1);
        } else {
            alert('Erro: ' + resp.msg);
        }
    });
}

// Função para atualizar o badge da fila
function updateQueueBadge() {
    const badgeElement = $('.btn-info .fa-shopping-cart').parent().find('.queue-badge');
    if (badgeElement.length) {
        badgeElement.text(processingQueue.length);
    } else {
        $('.btn-info .fa-shopping-cart').parent().append(` <span class="badge badge-light queue-badge">${processingQueue.length}</span>`);
    }
    
    $('.btn-success').prop('disabled', processingQueue.length === 0);
}

// Event delegation para botões de toggle queue
$(document).on('click', '.btn-toggle-queue', function() {
    const productId = $(this).data('product-id');
    const inQueue = $(this).data('in-queue');
    toggleQueue(productId, inQueue, $(this));
});

// Botão para abrir modal de produtos existentes
$(document).on('click', '#btnProcessarExistentes', function(e) {
    e.preventDefault();
    $('#modalProdutosExistentes').modal('show');
    carregarProdutosExistentes(1);
});

// Pesquisa em tempo real
let searchTimeout;
$(document).on('input', '#searchProdutosExistentes', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        carregarProdutosExistentes(1);
    }, 500);
});

$(document).on('change', '#perPageProdutosExistentes', function() {
    carregarProdutosExistentes(1);
});

// Inicializar a fila vazia quando o modal é aberto
$(document).on('show.bs.modal', '#modalProdutosExistentes', function() {
    processingQueue = [];
    updateQueueBadge();
});


