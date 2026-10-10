import Alpine from 'alpinejs'

Alpine.data('bodyPreview', (html, text) => ({
    mode: 'html',
    html,
    text,
    setMode(mode) {
        this.mode = mode
        this.$nextTick(() => this.render())
    },
    init() {
        this.render()
    },
    // The frame is sandboxed without allow-scripts, so the mail's own code never
    // runs; allow-same-origin only lets this page measure the rendered height.
    fitToContent() {
        const frame = this.$refs.frame
        const doc = frame?.contentDocument
        if (!doc) {
            return
        }
        const height = Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight ?? 0)
        frame.style.height = `${Math.max(height, 160)}px`
    },
    render() {
        const frame = this.$refs.frame
        if (!frame) {
            return
        }
        // Collapse first: the document's scrollHeight never reports less than the
        // frame's current height, so measuring at the old height can only grow.
        frame.style.height = '160px'
        if (this.mode === 'html') {
            frame.srcdoc = this.html || '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;color:#888;padding:24px"><em>No HTML body recorded.</em></body>'
        } else if (this.mode === 'text') {
            const escaped = (this.text || 'No text body recorded.')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
            frame.srcdoc = `<!doctype html><meta charset="utf-8"><pre style="font-family:ui-monospace,monospace;white-space:pre-wrap;padding:24px;margin:0;font-size:13px;line-height:1.6;color:#27272a">${escaped}</pre>`
        } else {
            const escaped = (this.html || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
            frame.srcdoc = `<!doctype html><meta charset="utf-8"><pre style="font-family:ui-monospace,monospace;white-space:pre-wrap;padding:24px;margin:0;font-size:12px;line-height:1.55;color:#52525b">${escaped}</pre>`
        }
    },
}))

Alpine.data('copyButton', (value) => ({
    copied: false,
    async copy() {
        try {
            await navigator.clipboard.writeText(value)
            this.copied = true
            setTimeout(() => (this.copied = false), 1200)
        } catch (_e) {
            this.copied = false
        }
    },
}))

Alpine.data('sendsTable', () => ({
    expanded: null,
    resending: null,
    toggle(id) {
        this.expanded = this.expanded === id ? null : id
    },
    isOpen(id) {
        return this.expanded === id
    },
    toggleResend(id) {
        this.resending = this.resending === id ? null : id
    },
    isResending(id) {
        return this.resending === id
    },
}))

Alpine.data('testSendModal', () => ({
    open: false,
    show() {
        this.open = true
        this.$nextTick(() => this.$refs.email?.focus())
    },
    hide() {
        this.open = false
    },
}))

window.Alpine = Alpine
Alpine.start()
