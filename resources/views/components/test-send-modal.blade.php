<div
    x-data="testSendModal()"
    x-cloak
    @mail-log:test-send-open.window="show()"
    @keydown.escape.window="hide()"
>
    <button
        type="button"
        @click="show()"
        class="ml-1 inline-flex items-center gap-1.5 rounded-md bg-zinc-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-zinc-800"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
        Test send
    </button>

    <div x-show="open" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/40 px-4">
        <div
            @click.away="hide()"
            class="w-full max-w-md rounded-lg border border-zinc-200 bg-white shadow-xl"
        >
            <form
                method="POST"
                action="{{ route('mail-log.test-send') }}"
                enctype="multipart/form-data"
                class="space-y-4 p-5"
            >
                @csrf

                <div>
                    <h2 class="text-base font-semibold text-zinc-900">Send a test email</h2>
                    <p class="mt-1 text-xs text-zinc-500">Sends a test email from this application, then shows the result as a Mail Log group.</p>
                </div>

                @if ($errors->any())
                    <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                        @foreach ($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <div>
                    <label for="mail-log-test-email" class="block text-xs font-medium text-zinc-700">Recipient</label>
                    <input
                        x-ref="email"
                        id="mail-log-test-email"
                        type="email"
                        name="email"
                        required
                        placeholder="you@example.com"
                        value="{{ old('email') }}"
                        class="mt-1 block w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-100"
                    >
                </div>

                <div>
                    <label for="mail-log-test-message" class="block text-xs font-medium text-zinc-700">Message</label>
                    <textarea
                        id="mail-log-test-message"
                        name="message"
                        rows="3"
                        class="mt-1 block w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-100"
                        placeholder="Message to send (leave empty for the default)"
                    >{{ old('message') }}</textarea>
                </div>

                <div>
                    <label for="mail-log-test-attachments" class="block text-xs font-medium text-zinc-700">Attachments</label>
                    <input
                        id="mail-log-test-attachments"
                        type="file"
                        name="attachments[]"
                        multiple
                        class="mt-1 block w-full text-xs text-zinc-600 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-zinc-700 hover:file:bg-zinc-200"
                    >
                </div>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <button type="button" @click="hide()" class="rounded-md px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-100">Cancel</button>
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-zinc-800">
                        Send test email
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
