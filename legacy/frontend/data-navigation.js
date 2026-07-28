(() => {
  'use strict';

  const PAGE_SIZES = [5, 10, 25, 50];
  const DEFAULT_PAGE_SIZE = 10;

  function isPlaceholderRow(row) {
    const text = (row.textContent || '').trim().toLowerCase();
    return row.cells.length === 1 && (text.startsWith('loading') || text.startsWith('no ') || text.includes('failed'));
  }

  class TablePager {
    constructor(tbody) {
      this.tbody = tbody;
      this.page = 1;
      this.pageSize = Number(tbody.dataset.pageSize || DEFAULT_PAGE_SIZE);
      this.scheduled = false;
      this.controls = this.createControls();
      this.observer = new MutationObserver(() => this.scheduleRefresh(true));
      this.observer.observe(tbody, { childList: true });
      this.scheduleRefresh(true);
    }

    createControls() {
      const host = document.createElement('div');
      const lightTheme = document.body.classList.contains('bg-slate-50') || document.body.classList.contains('bg-white');
      host.className = lightTheme
        ? 'table-pager hidden flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500'
        : 'table-pager hidden flex-wrap items-center justify-between gap-3 border-t border-slate-800 bg-slate-950/70 px-4 py-3 text-xs text-slate-400';
      host.innerHTML = `
        <div class="flex items-center gap-2">
          <span data-page-summary>0 records</span>
          <label class="flex items-center gap-2">Rows
            <select data-page-size class="rounded border border-slate-400 bg-transparent px-2 py-1">
              ${PAGE_SIZES.map(size => `<option value="${size}"${size === this.pageSize ? ' selected' : ''}>${size}</option>`).join('')}
            </select>
          </label>
        </div>
        <div class="flex items-center gap-1">
          <button type="button" data-page-first class="rounded border border-slate-400 px-2 py-1 hover:border-emerald-500 hover:text-emerald-500">First</button>
          <button type="button" data-page-prev class="rounded border border-slate-400 px-2 py-1 hover:border-emerald-500 hover:text-emerald-500">Prev</button>
          <span data-page-label class="min-w-[90px] text-center">Page 1 of 1</span>
          <button type="button" data-page-next class="rounded border border-slate-400 px-2 py-1 hover:border-emerald-500 hover:text-emerald-500">Next</button>
          <button type="button" data-page-last class="rounded border border-slate-400 px-2 py-1 hover:border-emerald-500 hover:text-emerald-500">Last</button>
        </div>`;

      const wrapper = this.tbody.closest('.overflow-x-auto') || this.tbody.closest('table')?.parentElement;
      if (wrapper && wrapper.parentElement) wrapper.insertAdjacentElement('afterend', host);
      else this.tbody.closest('table')?.insertAdjacentElement('afterend', host);

      host.querySelector('[data-page-size]').addEventListener('change', e => {
        this.pageSize = Number(e.target.value || DEFAULT_PAGE_SIZE);
        this.page = 1;
        this.refresh();
      });
      host.querySelector('[data-page-first]').addEventListener('click', () => { this.page = 1; this.refresh(); });
      host.querySelector('[data-page-prev]').addEventListener('click', () => { this.page = Math.max(1, this.page - 1); this.refresh(); });
      host.querySelector('[data-page-next]').addEventListener('click', () => { this.page += 1; this.refresh(); });
      host.querySelector('[data-page-last]').addEventListener('click', () => { this.page = this.totalPages(); this.refresh(); });
      return host;
    }

    rows() {
      return Array.from(this.tbody.children).filter(row => row.tagName === 'TR' && !isPlaceholderRow(row));
    }

    totalPages() {
      return Math.max(1, Math.ceil(this.rows().length / this.pageSize));
    }

    scheduleRefresh(resetPage = false) {
      if (resetPage) this.page = 1;
      if (this.scheduled) return;
      this.scheduled = true;
      requestAnimationFrame(() => {
        this.scheduled = false;
        this.refresh();
      });
    }

    refresh() {
      const rows = this.rows();
      const total = rows.length;
      const pages = Math.max(1, Math.ceil(total / this.pageSize));
      this.page = Math.min(Math.max(1, this.page), pages);
      const start = (this.page - 1) * this.pageSize;
      const end = start + this.pageSize;
      rows.forEach((row, index) => { row.hidden = index < start || index >= end; });

      const shouldShow = total > this.pageSize;
      this.controls.classList.toggle('hidden', !shouldShow);
      this.controls.classList.toggle('flex', shouldShow);
      this.controls.querySelector('[data-page-summary]').textContent = total ? `${start + 1}–${Math.min(end, total)} of ${total} records` : '0 records';
      this.controls.querySelector('[data-page-label]').textContent = `Page ${this.page} of ${pages}`;
      this.controls.querySelector('[data-page-first]').disabled = this.page === 1;
      this.controls.querySelector('[data-page-prev]').disabled = this.page === 1;
      this.controls.querySelector('[data-page-next]').disabled = this.page === pages;
      this.controls.querySelector('[data-page-last]').disabled = this.page === pages;
      this.controls.querySelectorAll('button:disabled').forEach(button => button.classList.add('opacity-40', 'cursor-not-allowed'));
      this.controls.querySelectorAll('button:not(:disabled)').forEach(button => button.classList.remove('opacity-40', 'cursor-not-allowed'));
    }
  }

  function initTablePagination() {
    document.querySelectorAll('tbody[id]').forEach(tbody => {
      if (!tbody.dataset.pagerReady) {
        tbody.dataset.pagerReady = '1';
        new TablePager(tbody);
      }
    });
  }

  function initBackToTop() {
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-label', 'Back to top');
    button.textContent = '↑ Top';
    button.className = 'fixed bottom-5 right-5 z-40 hidden rounded-full border border-slate-700 bg-slate-900/95 px-4 py-2 text-xs font-semibold text-slate-200 shadow-lg backdrop-blur hover:border-emerald-500 hover:text-emerald-400';
    button.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    document.body.appendChild(button);
    const update = () => button.classList.toggle('hidden', window.scrollY < 500);
    window.addEventListener('scroll', update, { passive: true });
    update();
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.documentElement.style.scrollBehavior = 'smooth';
    initTablePagination();
    initBackToTop();
  });
})();
