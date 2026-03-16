function meilisearchToggle(btn) {
  btn.classList.toggle('open');
  btn.setAttribute('aria-expanded', btn.classList.contains('open'));
  btn.nextElementSibling.classList.toggle('open');
}

function meilisearchShowMore(btn) {
  const group = btn.closest('.meilisearch-facet-body, .meilisearch-facet-sub-group');
  group.querySelectorAll('.meilisearch-facet-item--hidden').forEach(el => {
    el.classList.remove('meilisearch-facet-item--hidden');
  });
  btn.style.display = 'none';
}

function meilisearchSyncTags() {
  const container = document.getElementById('meilisearch-active-tags');
  if (!container) return;
  container.innerHTML = '';
  document.querySelectorAll('.meilisearch-facet-checkbox:checked').forEach(cb => {
    const tag = document.createElement('div');
    tag.className = 'meilisearch-active-tag';
    tag.innerHTML = cb.dataset.label +
      '<button onclick="document.getElementById(\'' + cb.id + '\').checked=false;meilisearchSyncTags()">×</button>';
    container.appendChild(tag);
  });
}

function meilisearchResetAll() {
  document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => cb.checked = false);
  meilisearchSyncTags();
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => {
    cb.addEventListener('change', meilisearchSyncTags);
  });
});