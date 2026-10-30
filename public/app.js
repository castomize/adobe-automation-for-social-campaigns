'use strict';

const form = document.querySelector('#campaign');
const feedback = document.querySelector('#feedback');
const result = document.querySelector('#result');
const button = document.querySelector('#generate-button');
const buttonMarkup = button.innerHTML;
let currentCampaign = null;
let busy = false;
const fieldIds = {
  campaign_message: 'campaign_message',
  audience: 'audience',
  market: 'market',
  language: 'language',
  visual_direction: 'visual_direction',
  asset_0: 'asset-0',
  asset_1: 'asset-1',
  'products.0.name': 'product-0-name',
  'products.0.description': 'product-0-description',
  'products.1.name': 'product-1-name',
  'products.1.description': 'product-1-description',
};

function clearErrors() {
  document.querySelectorAll('.field-error').forEach(node => node.remove());
  form.querySelectorAll('[aria-invalid]').forEach(node => {
    node.removeAttribute('aria-invalid');
    node.setAttribute('aria-describedby', (node.getAttribute('aria-describedby') || '').split(' ').filter(id => !id.endsWith('-error')).join(' '));
  });
}

function showErrors(errors) {
  feedback.replaceChildren();
  feedback.className = 'feedback error';
  const title = document.createElement('strong');
  title.textContent = 'Please check the following:';
  const list = document.createElement('ul');
  Object.entries(errors).forEach(([key, message]) => {
    const item = document.createElement('li');
    const field = document.getElementById(fieldIds[key]);
    if (field) {
      field.setAttribute('aria-invalid', 'true');
      const error = document.createElement('p');
      error.id = `${field.id}-error`;
      error.className = 'field-error';
      error.textContent = message;
      field.insertAdjacentElement('afterend', error);
      field.setAttribute('aria-describedby', `${field.getAttribute('aria-describedby') || ''} ${error.id}`.trim());
      const link = document.createElement('a');
      link.href = `#${field.id}`;
      link.textContent = `${field.labels?.[0]?.textContent.trim() || 'Field'}: ${message}`;
      item.append(link);
    } else {
      item.textContent = message;
    }
    list.append(item);
  });
  feedback.append(title, list);
  feedback.hidden = false;
  feedback.focus();
}

form.addEventListener('input', () => {
  result.hidden = true;
  if (!button.disabled) feedback.hidden = true;
});

const sources = [0, 1].map(i => ({
  image: document.getElementById(`source-preview-${i}`),
  caption: document.getElementById(`source-${i}`),
  savedUrl: '', savedLabel: 'No source selected.', objectUrl: null,
}));

function showSource(source, url, label) {
  source.image.hidden = !url;
  if (url) source.image.src = url;
  else source.image.removeAttribute('src');
  source.image.alt = label;
  source.caption.textContent = label;
}

sources.forEach((source, i) => {
  source.image.addEventListener('error', () => { source.image.hidden = true; });
  document.getElementById(`asset-${i}`).addEventListener('change', event => {
    if (source.objectUrl) URL.revokeObjectURL(source.objectUrl);
    const file = event.target.files[0];
    source.objectUrl = file && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type) ? URL.createObjectURL(file) : null;
    showSource(source, file ? source.objectUrl : source.savedUrl, file ? `${file.name} · selected for validation.` : source.savedLabel);
  });
});

function renderResults(data) {
  const gallery = document.getElementById('gallery');
  gallery.replaceChildren();
  data.brief.products.forEach((product, i) => {
    document.getElementById(`asset-${i}`).value = '';
    document.getElementById(`product-${i}-id`).value = product.id;
    const query = `run=${encodeURIComponent(data.brief.run_id)}&product=${encodeURIComponent(product.id)}`;
    const source = sources[i];
    if (source.objectUrl) URL.revokeObjectURL(source.objectUrl);
    source.objectUrl = null;
    source.savedUrl = product.asset.path ? `/asset?${query}` : '';
    source.savedLabel = product.asset.path
      ? ({ user_upload: 'Saved uploaded image.', example: 'Approved example image.' }[product.asset.origin] || 'Saved Cloudflare hero.')
      : 'No source available. Upload an image or resolve the error.';
    showSource(source, source.savedUrl, source.savedLabel);

    const outputs = data.manifest.outputs.filter(output => output.product_id === product.id);
    if (!outputs.length) return;
    const section = document.createElement('section');
    const heading = document.createElement('h3');
    heading.id = `results-${product.id}`;
    heading.textContent = product.name;
    section.setAttribute('aria-labelledby', heading.id);
    const cards = document.createElement('div');
    cards.className = 'gallery';
    outputs.forEach(output => {
      const url = `/output?${query}&ratio=${encodeURIComponent(output.ratio)}`;
      const figure = document.createElement('figure');
      const preview = document.createElement('a');
      preview.className = 'creative-preview';
      preview.href = url;
      preview.target = '_blank';
      preview.rel = 'noopener';
      preview.setAttribute('aria-label', `View full ${output.ratio} image for ${product.name} (new tab)`);
      const image = document.createElement('img');
      image.src = url;
      image.alt = `${product.name}, ${output.ratio} campaign: ${data.brief.campaign_message}`;
      image.width = output.width;
      image.height = output.height;
      preview.append(image);
      const caption = document.createElement('figcaption');
      const format = document.createElement('strong');
      format.textContent = output.ratio.charAt(0).toUpperCase() + output.ratio.slice(1);
      const dimensions = document.createElement('span');
      dimensions.className = 'help';
      dimensions.textContent = `${output.width} × ${output.height}`;
      const download = document.createElement('a');
      download.href = url + '&download=1';
      download.textContent = 'Download PNG';
      download.setAttribute('aria-label', `Download ${product.name}, ${output.ratio} PNG`);
      caption.append(format, dimensions, download);
      figure.append(preview, caption, cropControls(product, output));
      cards.append(figure);
    });
    section.append(heading, cards);
    gallery.append(section);
  });
  document.getElementById('bundle-download').href = data.bundle_url;
  result.hidden = data.manifest.outputs.length === 0;
}

function cropControls(product, output) {
  const details = document.createElement('details');
  details.className = 'crop-controls';
  const summary = document.createElement('summary');
  summary.textContent = 'Adjust crop';
  details.append(summary);
  const sliders = {};
  const framing = output.framing || { x: 0, y: 0, zoom: 100 };
  [['x', 'Horizontal', -20, 20], ['y', 'Vertical', -20, 20], ['zoom', 'Scale', 85, 150]].forEach(([key, title, min, max]) => {
    const label = document.createElement('label');
    label.textContent = title;
    const value = document.createElement('output');
    const slider = document.createElement('input');
    slider.type = 'range';
    slider.min = min;
    slider.max = max;
    slider.value = framing[key];
    slider.id = `crop-${product.id}-${output.ratio}-${key}`;
    label.htmlFor = slider.id;
    value.htmlFor = slider.id;
    const update = () => { value.textContent = `${slider.value}%`; };
    slider.addEventListener('input', update);
    update();
    label.append(value);
    details.append(label, slider);
    sliders[key] = slider;
  });
  const actions = document.createElement('div');
  actions.className = 'crop-actions';
  const apply = actionButton('Apply crop', async () => {
    const values = Object.fromEntries(Object.entries(sliders).map(([key, slider]) => [`crop[${key}]`, slider.value]));
    await perform('Rendering a new version with the saved photographs…', async () => {
      const data = await campaignAction('crop', { run: currentCampaign.brief.run_id, product: product.id, ratio: output.ratio, ...values });
      loadCampaign(data);
      await refreshHistory();
      document.getElementById(`results-${product.id}`).scrollIntoView({ block: 'start' });
    });
  });
  const reset = actionButton('Reset', () => {
    Object.entries(sliders).forEach(([key, slider]) => {
      slider.value = key === 'zoom' ? 100 : 0;
      slider.dispatchEvent(new Event('input'));
    });
  });
  actions.append(apply, reset);
  const help = document.createElement('p');
  help.className = 'help';
  help.textContent = 'Apply to preview. Text stays fixed; no new imagery is generated.';
  details.append(actions, help);
  return details;
}

function actionButton(label, action) {
  const control = document.createElement('button');
  control.type = 'button';
  control.className = 'secondary';
  control.textContent = label;
  control.addEventListener('click', action);
  return control;
}

async function request(url, options = {}) {
  const response = await fetch(url, { ...options, headers: { Accept: 'application/json' } });
  const data = await response.json();
  if (!response.ok) throw { status: response.status, errors: data.errors || { request: 'The campaign could not be processed.' } };
  return data;
}

function campaignAction(action, values = {}) {
  const body = new URLSearchParams({ csrf: form.elements.csrf.value, action, ...values });
  return request('/api/campaign', { method: 'POST', body });
}

function rememberRun(id) {
  try {
    if (id) localStorage.setItem('melt-muse-last-run', id);
    else localStorage.removeItem('melt-muse-last-run');
  } catch { /* Saved history remains available when browser storage is disabled. */ }
}

function loadCampaign(data) {
  currentCampaign = data;
  ['campaign_message', 'audience', 'market', 'language', 'visual_direction'].forEach(key => {
    form.elements[key].value = data.brief[key];
  });
  data.brief.products.forEach((product, i) => {
    ['id', 'name', 'description'].forEach(key => { form.elements[`products[${i}][${key}]`].value = product[key]; });
  });
  document.getElementById('previous-run').value = data.brief.run_id;
  rememberRun(data.brief.run_id);
  renderResults(data);
  if (data.status === 'incomplete') {
    showErrors(data.manifest.errors);
    feedback.querySelector('strong').textContent = 'Campaign incomplete. Available images are saved below.';
  } else {
    feedback.className = 'feedback';
    feedback.textContent = 'Your six campaign images are ready.';
    feedback.hidden = false;
  }
}

async function refreshHistory() {
  const data = await request('/api/campaign');
  const history = document.getElementById('history');
  history.replaceChildren();
  document.getElementById('history-count').textContent = `(${data.runs.length})`;
  if (!data.runs.length) history.textContent = 'No saved campaigns yet. Load the example or generate a campaign.';
  data.runs.forEach(run => {
    const row = document.createElement('div');
    row.className = 'history-row';
    const info = document.createElement('div');
    const title = document.createElement('strong');
    title.textContent = run.message;
    const date = document.createElement('span');
    date.className = 'help';
    date.textContent = `${new Date(run.created_at).toLocaleString()} · ${run.status}${run.kept ? ' · kept' : ''}`;
    info.append(title, date);
    const actions = document.createElement('div');
    actions.className = 'history-actions';
    const open = actionButton('Open', () => perform('Opening saved campaign…', async () => {
      loadCampaign(await campaignAction('open', { run: run.run_id }));
      result.scrollIntoView({ block: 'start' });
    }));
    const keep = actionButton(run.kept ? 'Unkeep' : 'Keep', () => perform('Saving campaign preference…', async () => {
      await campaignAction('keep', { run: run.run_id, kept: run.kept ? '0' : '1' });
      await refreshHistory();
      feedback.textContent = run.kept ? 'Campaign can now be deleted.' : 'Campaign kept. It is protected from deletion.';
    }));
    keep.setAttribute('aria-pressed', String(run.kept));
    const remove = actionButton('Delete', () => perform('Moving campaign to trash…', async () => {
      await campaignAction('delete', { run: run.run_id });
      if (currentCampaign?.brief.run_id === run.run_id) {
        currentCampaign = null;
        document.getElementById('previous-run').value = '';
        sources.forEach(source => {
          source.savedUrl = '';
          source.savedLabel = 'No source selected.';
          showSource(source, '', source.savedLabel);
        });
        result.hidden = true;
        rememberRun(null);
      }
      await refreshHistory();
      feedback.textContent = 'Campaign moved to local storage/trash.';
    }));
    remove.disabled = run.kept;
    if (run.kept) remove.title = 'Unkeep this campaign before deleting it.';
    actions.append(open, keep, remove);
    row.append(info, actions);
    history.append(row);
  });
}

async function perform(message, action) {
  if (busy) return;
  busy = true;
  clearErrors();
  const controls = [...document.querySelectorAll('input, textarea, button')];
  const disabled = controls.map(control => control.disabled);
  controls.forEach(control => { control.disabled = true; });
  form.setAttribute('aria-busy', 'true');
  feedback.className = 'feedback';
  feedback.textContent = message;
  feedback.hidden = false;
  try {
    await action();
  } catch (error) {
    showErrors(error.errors || { request: 'The server response could not be read. Check the local server and reload saved campaigns before retrying.' });
  } finally {
    controls.forEach((control, i) => { control.disabled = disabled[i]; });
    form.removeAttribute('aria-busy');
    button.innerHTML = buttonMarkup;
    busy = false;
  }
}

form.addEventListener('submit', event => {
  event.preventDefault();
  if (!form.reportValidity()) return;
  const body = new FormData(form);
  currentCampaign?.brief.products.forEach((product, i) => {
    if (document.getElementById(`asset-${i}`).files.length) return;
    Object.entries(product.crops || {}).forEach(([ratio, crop]) => {
      Object.entries(crop).forEach(([key, value]) => body.append(`products[${i}][crops][${ratio}][${key}]`, value));
    });
  });
  perform('Validating sources, generating missing imagery if needed, and rendering your PNGs. New imagery can take up to 90 seconds.', async () => {
    result.hidden = true;
    button.textContent = 'Generating campaign…';
    loadCampaign(await request('/api/generate', { method: 'POST', body }));
    await refreshHistory();
    feedback.focus();
  });
});

document.getElementById('load-example').addEventListener('click', () => perform('Rendering the approved example with saved photographs…', async () => {
  loadCampaign(await campaignAction('example'));
  await refreshHistory();
  result.scrollIntoView({ block: 'start' });
}));

perform('Loading saved campaigns…', async () => {
  await refreshHistory();
  let id;
  try { id = localStorage.getItem('melt-muse-last-run'); } catch { /* History can still be opened manually. */ }
  if (!id) {
    feedback.hidden = true;
    return;
  }
  try {
    loadCampaign(await campaignAction('open', { run: id }));
  } catch (error) {
    if (error.status !== 404) throw error;
    rememberRun(null);
    feedback.textContent = 'The last campaign is no longer available. Open a saved campaign or load the example.';
  }
});
