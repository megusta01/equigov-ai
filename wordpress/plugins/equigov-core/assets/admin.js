/**
 * EquiGov — interface do painel (JavaScript puro, sem build).
 *
 * Segurança: todo conteúdo vindo da API/IA entra no DOM via textContent (função h),
 * nunca innerHTML. As permissões são decididas no servidor; aqui só escondemos botões.
 * Acessibilidade: rótulos em todos os campos, fieldset/legend nas perguntas, região aria-live
 * para mensagens, foco movido para o título a cada navegação.
 */
(function () {
  'use strict';

  const C = window.EQUIGOV;
  const app = document.getElementById('equigov-app');
  const live = document.getElementById('equigov-live');
  const noticeBox = document.getElementById('equigov-notice');

  const LABELS = {
    draft: 'Rascunho', in_progress: 'Em preenchimento', scored: 'Concluído',
    low: 'Baixo', moderate: 'Moderado', high: 'Alto', critical: 'Crítico',
    pending_review: 'Aguardando revisão', in_review: 'Em revisão', reviewed: 'Revisado', approved: 'Aprovado', rejected: 'Rejeitado',
    pending: 'Pendente', accepted: 'Aceita', edited: 'Editada',
    grounded: 'Fundamentada', weak: 'Suporte fraco',
    alta: 'Alta', media: 'Média', baixa: 'Baixa',
  };
  const label = (k) => LABELS[k] || k || '—';

  // ---------- utilitários ----------

  function h(tag, props, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(props || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2).toLowerCase(), v);
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) {
      if (c === null || c === undefined || c === false) continue;
      el.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return el;
  }

  const badge = (value, kind) => h('span', { class: `eg-badge eg-${kind || 'status'}-${value}` }, label(value));

  async function api(method, path, body) {
    const res = await fetch(C.root + path, {
      method,
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': C.nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(data.message || `Erro ${res.status}`);
      err.code = data.code;
      err.data = data.data || {};
      throw err;
    }
    return data;
  }

  function notice(message, type) {
    noticeBox.replaceChildren(
      h('div', { class: `notice notice-${type || 'info'} is-dismissible` }, h('p', { text: message }))
    );
    live.textContent = message;
  }

  function busy(button, text) {
    const original = button.textContent;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = text;
    return () => { button.disabled = false; button.removeAttribute('aria-busy'); button.textContent = original; };
  }

  function render(...nodes) {
    // Achata listas e descarta vazios: replaceChildren converteria arrays e null em texto.
    app.replaceChildren(...nodes.flat(Infinity).filter((n) => n !== null && n !== undefined && n !== false));
    const title = app.querySelector('h2');
    if (title) { title.setAttribute('tabindex', '-1'); title.focus(); }
  }

  const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

  // ---------- roteamento ----------

  async function route() {
    noticeBox.replaceChildren();
    const [, kind, id] = (location.hash.match(/^#\/(d|p)\/(\d+)/) || []);
    try {
      if (kind === 'd') await diagnosisView(Number(id));
      else if (kind === 'p') await planView(Number(id));
      else await listView();
    } catch (e) {
      render(h('h2', { text: 'Não foi possível carregar' }), h('p', { text: e.message }), h('a', { href: '#/' }, '← Voltar'));
    }
  }

  // ---------- lista de diagnósticos ----------

  async function listView() {
    const [diagnoses, questionnaires] = await Promise.all([api('GET', '/diagnoses'), api('GET', '/questionnaires')]);

    const table = diagnoses.length
      ? h('table', { class: 'widefat striped' },
          h('caption', { class: 'screen-reader-text', text: 'Diagnósticos da sua instituição' }),
          h('thead', {}, h('tr', {}, ['Diagnóstico', 'Situação', 'Nota de risco', 'Nível', 'Criado em'].map((t) => h('th', { scope: 'col', text: t })))),
          h('tbody', {}, diagnoses.map((d) => h('tr', {},
            h('td', {}, h('a', { href: `#/d/${d.id}`, text: d.title })),
            h('td', {}, label(d.status)),
            h('td', { text: d.score ?? '—' }),
            h('td', {}, d.risk_level ? badge(d.risk_level, 'risk') : '—'),
            h('td', { text: fmtDate(d.created_at) }),
          ))))
      : h('p', { text: 'Nenhum diagnóstico ainda.' });

    let create = null;
    if (C.canAnswer && questionnaires.length) {
      const select = h('select', { id: 'eg-q' }, questionnaires.map((q) => h('option', { value: q.id, text: `${q.title} (v${q.version})` })));
      const title = h('input', { id: 'eg-title', type: 'text', class: 'regular-text', maxlength: '200', placeholder: 'Ex.: Unidade Centro — 2º semestre' });
      const button = h('button', { type: 'submit', class: 'button button-primary', text: 'Iniciar diagnóstico' });
      create = h('form', { class: 'eg-card', onSubmit: async (ev) => {
        ev.preventDefault();
        const done = busy(button, 'Criando…');
        try {
          const d = await api('POST', '/diagnoses', { questionnaire_id: Number(select.value), title: title.value.trim() || undefined });
          location.hash = `#/d/${d.id}`;
        } catch (e) { notice(e.message, 'error'); done(); }
      } },
        h('h3', { text: 'Novo diagnóstico' }),
        h('p', {}, h('label', { for: 'eg-q', text: 'Questionário ' }), select),
        h('p', {}, h('label', { for: 'eg-title', text: 'Título (opcional) ' }), title),
        button);
    }

    render(h('h2', { text: 'Diagnósticos' }), h('p', { class: 'description', text: `Você está conectado como ${C.userName}.` }), create, table);
  }

  // ---------- diagnóstico: formulário ou relatório ----------

  async function diagnosisView(id) {
    const d = await api('GET', `/diagnoses/${id}`);
    if (d.status === 'scored') return reportView(d);
    return formView(d);
  }

  /** Mesma gramática de condições do servidor (ConditionEvaluator). */
  function visible(cond, answers) {
    if (!cond) return true;
    if (cond.all) return cond.all.every((c) => visible(c, answers));
    if (cond.any) return cond.any.some((c) => visible(c, answers));
    const a = answers[cond.question];
    const values = Array.isArray(a) ? a : (a == null ? [] : [a]);
    switch (cond.op) {
      case 'equals': return values.includes(cond.value);
      case 'not_equals': return values.length > 0 && !values.includes(cond.value);
      case 'in': return values.some((v) => [].concat(cond.value).includes(v));
      case 'not_empty': return values.length > 0;
      default: return false;
    }
  }

  function formView(d) {
    const q = d.questionnaire;
    const answers = { ...(d.answers || {}) };
    const editable = C.canAnswer;
    const blocks = {};
    const progress = h('p', { class: 'eg-progress', 'aria-live': 'polite' });

    /** Recalcula visibilidade em ordem, usando só respostas visíveis (como o servidor). */
    function refresh() {
      const shown = {};
      let total = 0; let answered = 0;
      for (const question of q.questions) {
        const isVisible = visible(question.show_if, shown);
        blocks[question.key].hidden = !isVisible;
        blocks[question.key].querySelectorAll('input').forEach((i) => { i.disabled = !isVisible || !editable; });
        if (!isVisible) continue;
        total++;
        if (question.key in answers) { shown[question.key] = answers[question.key]; answered++; }
      }
      progress.textContent = `${answered} de ${total} perguntas visíveis respondidas.`;
      return shown;
    }

    const fieldsets = q.questions.map((question) => {
      const multi = question.type === 'multi_choice';
      const name = `eg-${question.key}`;
      const options = question.options.map((o, i) => {
        const inputId = `${name}-${i}`;
        const current = answers[question.key];
        const checked = multi ? (current || []).includes(o.value) : current === o.value;
        const input = h('input', { type: multi ? 'checkbox' : 'radio', id: inputId, name, value: o.value, checked, onChange: () => {
          if (multi) {
            answers[question.key] = [...blocks[question.key].querySelectorAll('input:checked')].map((x) => x.value);
          } else {
            answers[question.key] = o.value;
          }
          refresh();
        } });
        return h('li', {}, input, ' ', h('label', { for: inputId, text: o.label }));
      });
      const legend = h('legend', {}, question.text, question.required ? '' : h('span', { class: 'eg-muted', text: ' (opcional)' }));
      const hint = multi ? h('p', { class: 'description', text: 'Marque todas que se aplicam. Se nenhuma, deixe em branco e salve.' }) : null;
      blocks[question.key] = h('fieldset', { class: 'eg-question' }, legend, hint, h('ul', { class: 'eg-options' }, options));
      return blocks[question.key];
    });

    const byDimension = {};
    q.questions.forEach((question, i) => { (byDimension[question.dimension] ||= []).push(fieldsets[i]); });
    const sections = Object.entries(q.dimensions).map(([key, title]) => h('section', { class: 'eg-card' }, h('h3', { text: title }), byDimension[key] || []));

    const save = h('button', { type: 'button', class: 'button', text: 'Salvar rascunho' });
    const submit = h('button', { type: 'button', class: 'button button-primary', text: 'Enviar e calcular' });

    async function persist() {
      const shown = refresh();
      // Questões múltiplas visíveis e sem marcação contam como "nenhuma prática".
      for (const question of q.questions) {
        if (!blocks[question.key].hidden && question.type === 'multi_choice' && !(question.key in shown)) shown[question.key] = [];
      }
      if (Object.keys(shown).length) await api('PATCH', `/diagnoses/${d.id}/answers`, { answers: shown });
    }

    save.addEventListener('click', async () => {
      const done = busy(save, 'Salvando…');
      try { await persist(); notice('Rascunho salvo.', 'success'); } catch (e) { notice(e.message, 'error'); }
      done();
    });

    submit.addEventListener('click', async () => {
      const done = busy(submit, 'Calculando…');
      try {
        await persist();
        await api('POST', `/diagnoses/${d.id}/submit`);
        notice('Diagnóstico concluído.', 'success');
        await route();
      } catch (e) {
        const missing = e.data && e.data.missing;
        if (missing && missing.length) {
          const first = blocks[missing[0]];
          notice(`${e.message} Faltam ${missing.length}.`, 'error');
          if (first) { first.classList.add('eg-missing'); first.querySelector('input')?.focus(); }
        } else notice(e.message, 'error');
        done();
      }
    });

    render(
      h('p', {}, h('a', { href: '#/' }, '← Diagnósticos')),
      h('h2', { text: d.title }),
      h('p', { class: 'description', text: `${q.title} · versão ${d.questionnaire_version} · ${label(d.status)}` }),
      progress,
      sections,
      editable ? h('p', { class: 'eg-actions' }, save, ' ', submit) : h('p', { class: 'description', text: 'Somente leitura para o seu perfil.' }),
    );
    refresh();
  }

  async function reportView(d) {
    const [report, plans] = await Promise.all([api('GET', `/diagnoses/${d.id}/report`), api('GET', `/diagnoses/${d.id}/action-plans`)]);

    const dims = h('table', { class: 'widefat eg-dims' },
      h('caption', { text: 'Risco por dimensão (0 = sem barreiras, 100 = barreiras máximas)' }),
      h('thead', {}, h('tr', {}, h('th', { scope: 'col', text: 'Dimensão' }), h('th', { scope: 'col', text: 'Nota' }))),
      h('tbody', {}, Object.values(report.dimensions).map((dim) => h('tr', {},
        h('th', { scope: 'row', text: dim.label }),
        h('td', {}, dim.score === null ? 'não avaliada' : h('span', {}, h('meter', { min: '0', max: '100', value: dim.score, 'aria-hidden': 'true' }), ` ${dim.score}`)),
      ))));

    const findings = h('ol', { class: 'eg-findings' }, report.findings.map((f) => h('li', { class: 'eg-card' },
      h('h4', {}, f.critical ? badge('critical', 'risk') : null, ' ', f.question_text),
      h('p', {}, h('strong', { text: 'Resposta: ' }), f.answer),
      h('p', { text: f.message }),
      h('p', { class: 'eg-muted' }, `Regra: ${f.rule} · Contribuição: ${f.contribution} pontos`, f.reference ? ` · Referência: ${f.reference}` : ''),
    )));

    const planList = plans.length
      ? h('ul', {}, plans.map((p) => h('li', {}, h('a', { href: `#/p/${p.id}`, text: p.title }), ' ', badge(p.status), ` · ${p.items} sugestões · ${fmtDate(p.created_at)}`)))
      : h('p', { text: 'Nenhum plano gerado ainda.' });

    let generate = null;
    if (C.canManage) {
      const ctx = h('textarea', { id: 'eg-ctx', rows: '3', class: 'large-text', maxlength: '1000', placeholder: 'Ex.: município de 80 mil habitantes, 2 unidades de atendimento, orçamento restrito.' });
      const max = h('select', { id: 'eg-max' }, [3, 5, 8].map((n) => h('option', { value: n, text: n, selected: n === 5 })));
      const button = h('button', { type: 'submit', class: 'button button-primary', text: 'Gerar sugestões com IA' });
      generate = h('form', { class: 'eg-card', onSubmit: async (ev) => {
        ev.preventDefault();
        const done = busy(button, 'Gerando sugestões… pode levar até 1 minuto');
        live.textContent = 'Gerando sugestões com IA.';
        try {
          const plan = await api('POST', `/diagnoses/${d.id}/action-plans`, { context: ctx.value.trim(), max_actions: Number(max.value) });
          location.hash = `#/p/${plan.id}`;
        } catch (e) {
          notice(e.message, 'error');
          if (e.code === 'eg_plan_open' && e.data.plan_id) noticeBox.append(h('p', {}, h('a', { href: `#/p/${e.data.plan_id}`, text: 'Abrir o plano em revisão' })));
          done();
        }
      } },
        h('h3', { text: 'Gerar plano de ação' }),
        h('p', { class: 'description', text: 'A IA sugere medidas fundamentadas na biblioteca de normas. Nenhuma sugestão vale antes de revisão e aprovação humanas.' }),
        h('p', {}, h('label', { for: 'eg-ctx', text: 'Contexto da instituição (opcional)' }), h('br'), ctx),
        h('p', {}, h('label', { for: 'eg-max', text: 'Número máximo de sugestões ' }), max),
        button);
    }

    render(
      h('p', {}, h('a', { href: '#/' }, '← Diagnósticos')),
      h('h2', { text: d.title }),
      h('div', { class: 'eg-score eg-card' },
        h('p', { class: 'eg-score-value' }, h('span', { class: 'screen-reader-text', text: 'Nota de risco: ' }), String(report.score)),
        h('p', {}, 'Nível de risco: ', badge(report.risk_level, 'risk')),
        report.critical_count ? h('p', { text: `${report.critical_count} resposta(s) crítica(s) elevaram o nível.` }) : null,
        h('p', { class: 'eg-muted', text: `Regra de cálculo: ${report.rule_version} · concluído em ${fmtDate(report.scored_at)}` })),
      dims,
      h('h3', { text: `Achados (${report.findings.length})` }),
      findings,
      h('h3', { text: 'Planos de ação' }),
      planList,
      generate,
    );
  }

  // ---------- plano de ação: revisão e aprovação ----------

  async function planView(id) {
    const p = await api('GET', `/action-plans/${id}`);
    const reviewing = C.canReview && ['pending_review', 'in_review'].includes(p.status);
    const ai = p.ai || {};
    const v = ai.verification || {};

    const items = p.items.map((item) => itemCard(p, item, reviewing));

    const discarded = p.rejected_by_verifier.length
      ? h('details', { class: 'eg-card' },
          h('summary', { text: `${p.rejected_by_verifier.length} sugestão(ões) descartada(s) automaticamente pelo verificador` }),
          h('ul', {}, p.rejected_by_verifier.map((r) => h('li', {}, h('strong', { text: r.title }), ' — ', (r.verification?.reasons || []).join('; ')))))
      : null;

    const footer = h('div', { class: 'eg-card eg-footer' });
    if (reviewing) {
      const b = h('button', { type: 'button', class: 'button button-primary', text: 'Concluir revisão' });
      b.addEventListener('click', async () => {
        const done = busy(b, 'Concluindo…');
        try { await api('POST', `/action-plans/${p.id}/complete-review`); notice('Revisão concluída. Outro gestor deve aprovar.', 'success'); await route(); }
        catch (e) { notice(e.message, 'error'); done(); }
      });
      footer.append(h('p', { text: 'Decida cada sugestão e conclua a revisão. A aprovação final é feita por outra pessoa.' }), b);
    }
    if (C.canApprove && p.status === 'reviewed') {
      const sameReviewer = p.reviewed_by && p.reviewed_by.id === C.userId;
      const approve = h('button', { type: 'button', class: 'button button-primary', text: 'Aprovar plano', disabled: sameReviewer });
      const reason = h('textarea', { id: 'eg-reason', rows: '2', class: 'large-text', maxlength: '1000' });
      const reject = h('button', { type: 'button', class: 'button', text: 'Rejeitar plano' });
      approve.addEventListener('click', async () => {
        const done = busy(approve, 'Aprovando…');
        try { await api('POST', `/action-plans/${p.id}/approve`); notice('Plano aprovado.', 'success'); await route(); }
        catch (e) { notice(e.message, 'error'); done(); }
      });
      reject.addEventListener('click', async () => {
        const done = busy(reject, 'Rejeitando…');
        try { await api('POST', `/action-plans/${p.id}/reject`, { reason: reason.value }); notice('Plano rejeitado.', 'success'); await route(); }
        catch (e) { notice(e.message, 'error'); done(); }
      });
      footer.append(
        h('p', { text: `Revisado por ${p.reviewed_by?.name || '—'}.` }),
        sameReviewer ? h('p', { class: 'eg-warning', text: 'Você revisou este plano e por isso não pode aprová-lo (separação de funções).' }) : null,
        approve,
        h('p', {}, h('label', { for: 'eg-reason', text: 'Motivo, se for rejeitar' }), h('br'), reason),
        reject,
      );
    }
    if (p.status === 'approved') {
      footer.append(
        h('h3', { text: 'Plano aprovado' }),
        h('p', { text: `Revisado por ${p.reviewed_by?.name || '—'} · aprovado por ${p.approved_by?.name || '—'} em ${fmtDate(p.decided_at)}` }),
        h('table', { class: 'widefat striped' },
          h('thead', {}, h('tr', {}, ['Ação', 'Prioridade', 'Prazo', 'Indicador', 'Origem'].map((t) => h('th', { scope: 'col', text: t })))),
          h('tbody', {}, p.final_actions.map((a) => h('tr', {},
            h('td', {}, h('strong', { text: a.title }), a.edited ? ' (editada)' : '', h('br'), a.description),
            h('td', { text: label(a.priority) }),
            h('td', { text: a.deadline_days ? `${a.deadline_days} dias` : '—' }),
            h('td', { text: a.indicator || '—' }),
            h('td', { class: 'eg-muted' }, `Achados: ${a.findings.join(', ')}`, h('br'), `Fontes: ${a.sources.join('; ')}`),
          )))));
    }
    if (p.status === 'rejected') {
      footer.append(h('h3', { text: 'Plano rejeitado' }), h('p', { text: p.rejection_reason }), h('p', { class: 'eg-muted', text: `por ${p.approved_by?.name || '—'} em ${fmtDate(p.decided_at)}` }));
    }

    render(
      h('p', {}, h('a', { href: `#/d/${p.diagnosis_id}` }, '← Diagnóstico')),
      h('h2', { text: p.title }),
      h('p', {}, badge(p.status), ' ', h('span', { class: 'eg-muted', text: `Gerado em ${fmtDate(p.created_at)}` })),
      h('p', { class: 'eg-muted eg-ai-meta', text:
        `IA: ${ai.model || '—'} (${ai.provider || '—'}) · prompt ${ai.prompt_version || '—'} · ` +
        `${v.grounded ?? 0} fundamentada(s), ${v.weak ?? 0} com suporte fraco, ${v.rejected ?? 0} descartada(s) · ` +
        `citações válidas: ${v.citation_validity == null ? '—' : Math.round(v.citation_validity * 100) + '%'}` +
        (ai.usage?.total_tokens ? ` · ${ai.usage.total_tokens} tokens` : '') + (ai.cache_hit ? ' · do cache' : '') }),
      p.context ? h('p', {}, h('strong', { text: 'Contexto informado: ' }), p.context) : null,
      items.length ? items : h('p', { text: 'A IA não encontrou fundamento na biblioteca para sugerir ações.' }),
      discarded,
      footer.childNodes.length ? footer : null,
    );
  }

  function itemCard(plan, item, reviewing) {
    const o = item.origin;
    const vstatus = item.verification?.status;

    const sources = h('div', { class: 'eg-sources' }, h('h5', { text: 'Fontes citadas — confira o texto original' }),
      item.sources.map((s) => h('figure', { class: 'eg-source' },
        h('blockquote', { text: s.excerpt }),
        h('figcaption', {}, `${s.id} · `,
          s.source_url ? h('a', { href: s.source_url, target: '_blank', rel: 'noopener noreferrer', text: s.document }) : s.document,
          ` · p. ${s.page}`,
          item.verification?.support?.[s.id] != null ? ` · suporte ${Math.round(item.verification.support[s.id] * 100)}%` : ''),
      )));

    const findings = h('ul', { class: 'eg-muted' }, item.findings.map((f) =>
      h('li', {}, `Achado: ${f.question_text} → "${f.answer}". ${f.rule || ''}`)));

    const suggestion = h('div', { class: 'eg-suggestion' },
      h('h5', { text: 'Sugestão da IA' }),
      h('p', { text: o.description }),
      h('dl', {},
        h('dt', { text: 'Prioridade' }), h('dd', { text: label(o.priority) }),
        h('dt', { text: 'Prazo sugerido' }), h('dd', { text: o.deadline_days ? `${o.deadline_days} dias` : '—' }),
        h('dt', { text: 'Indicador' }), h('dd', { text: o.indicator || '—' }),
        h('dt', { text: 'Justificativa' }), h('dd', { text: o.rationale || '—' })),
      findings);

    let decision = null;
    if (item.decision !== 'pending') {
      decision = h('div', { class: `eg-decision eg-decision-${item.decision}` },
        h('p', {}, h('strong', { text: `Decisão: ${label(item.decision)}` }), ` em ${fmtDate(item.decided_at)}`),
        item.decision === 'edited' ? h('p', {}, h('strong', { text: 'Versão editada: ' }), `${item.final.title} — ${item.final.description}`) : null,
        item.note ? h('p', {}, h('strong', { text: 'Observação: ' }), item.note) : null);
    }

    let form = null;
    if (reviewing) {
      const uid = `eg-${plan.id}-${item.id}`;
      const radios = ['accepted', 'edited', 'rejected'].map((value) => h('span', { class: 'eg-radio' },
        h('input', { type: 'radio', name: `${uid}-d`, id: `${uid}-${value}`, value, checked: item.decision === value }),
        h('label', { for: `${uid}-${value}`, text: { accepted: 'Aceitar', edited: 'Aceitar com edição', rejected: 'Rejeitar' }[value] })));
      const f = item.final || o;
      const fields = {
        title: h('input', { type: 'text', id: `${uid}-title`, class: 'large-text', value: f.title, maxlength: '200' }),
        description: h('textarea', { id: `${uid}-description`, class: 'large-text', rows: '3', maxlength: '2000' }, f.description),
        priority: h('select', { id: `${uid}-priority` }, ['alta', 'media', 'baixa'].map((p) => h('option', { value: p, text: label(p), selected: p === f.priority }))),
        deadline_days: h('input', { type: 'number', id: `${uid}-deadline_days`, min: '1', max: '730', value: f.deadline_days ?? '' }),
        indicator: h('input', { type: 'text', id: `${uid}-indicator`, class: 'large-text', value: f.indicator || '', maxlength: '500' }),
      };
      const names = { title: 'Título', description: 'Descrição', priority: 'Prioridade', deadline_days: 'Prazo (dias)', indicator: 'Indicador' };
      const editBox = h('div', { class: 'eg-edit', hidden: item.decision !== 'edited' },
        Object.entries(fields).map(([k, el]) => h('p', {}, h('label', { for: el.id, text: names[k] }), h('br'), el)));
      const note = h('textarea', { id: `${uid}-note`, class: 'large-text', rows: '2', maxlength: '1000' }, item.note || '');
      const save = h('button', { type: 'submit', class: 'button', text: 'Registrar decisão' });

      const fieldset = h('fieldset', {}, h('legend', { text: 'Sua decisão' }), radios);
      fieldset.addEventListener('change', () => {
        editBox.hidden = fieldset.querySelector('input:checked')?.value !== 'edited';
      });

      form = h('form', { class: 'eg-review', onSubmit: async (ev) => {
        ev.preventDefault();
        const chosen = fieldset.querySelector('input:checked');
        if (!chosen) { notice('Escolha aceitar, editar ou rejeitar.', 'error'); return; }
        const body = { decision: chosen.value, note: note.value.trim() };
        if (chosen.value === 'edited') {
          Object.entries(fields).forEach(([k, el]) => { body[k] = k === 'deadline_days' ? (el.value ? Number(el.value) : undefined) : el.value; });
        }
        const done = busy(save, 'Registrando…');
        try { await api('PATCH', `/action-plans/${plan.id}/items/${item.id}`, body); notice('Decisão registrada.', 'success'); await route(); }
        catch (e) { notice(e.message, 'error'); done(); }
      } },
        fieldset,
        vstatus === 'weak' ? h('p', { class: 'eg-warning', text: 'Suporte fraco nas fontes: para aceitar, justifique na observação.' }) : null,
        editBox,
        h('p', {}, h('label', { for: note.id, text: 'Observação (obrigatória para rejeitar)' }), h('br'), note),
        save);
    }

    return h('article', { class: `eg-card eg-item eg-item-${item.decision}`, 'aria-labelledby': `eg-item-${plan.id}-${item.id}` },
      h('header', {},
        h('h4', { id: `eg-item-${plan.id}-${item.id}`, text: o.title }),
        badge(o.priority, 'priority'), ' ', badge(vstatus, 'verification'), ' ', badge(item.decision, 'decision')),
      h('div', { class: 'eg-grid' }, suggestion, sources),
      decision,
      form);
  }

  window.addEventListener('hashchange', route);
  route();
})();
