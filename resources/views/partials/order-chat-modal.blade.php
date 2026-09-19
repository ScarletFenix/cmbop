<div class="modal fade chat-modal" id="chatModal" tabindex="-1" aria-labelledby="chatModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div class="chat-header-main">
                    <h5 class="modal-title" id="chatModalTitle">
                        <i class="fa fa-comments" aria-hidden="true"></i>
                        <span>Order chat</span>
                        <span class="text-muted fw-normal">·</span>
                        <span id="chatOrderNumber"></span>
                    </h5>
                    <p id="chatPresence" class="chat-presence d-none" role="status">
                        <span class="chat-presence__dot" aria-hidden="true"></span>
                        <span class="chat-presence__text"></span>
                    </p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div id="chatOrderDetails" class="chat-order-details d-none" aria-live="polite"></div>
            <div class="modal-body p-0">
                <div id="chatMessages" class="chat-messages" role="log" aria-live="polite">
                    <div class="text-center text-muted py-5">
                        <i class="fa fa-spinner fa-spin fa-2x" aria-hidden="true"></i>
                        <p class="mt-2 mb-0">Loading messages…</p>
                    </div>
                </div>
                <div class="chat-composer">
                    <div class="chat-policy-strip" role="note">
                        <i class="fa fa-lock" aria-hidden="true"></i>
                        <span>Keep order talks here — personal contact details are not allowed.</span>
                    </div>
                    <div id="chatComposerNote" class="small text-muted pb-2 d-none" role="status"></div>
                    <form id="chatForm">
                        <input type="hidden" id="chatOrderId">
                        <div id="chatEmojiStrip" class="chat-emoji-strip" role="toolbar" aria-label="Professional reactions">
                            <button type="button" data-emoji="👍" aria-label="+1">👍</button>
                            <button type="button" data-emoji="👏" aria-label=":clap:">👏</button>
                            <button type="button" data-emoji="❤️" aria-label="<3">❤️</button>
                            <button type="button" data-emoji="💡" aria-label=":idea:">💡</button>
                            <button type="button" data-emoji="🤔" aria-label=":think:">🤔</button>
                            <button type="button" data-emoji="😊" aria-label=":)">😊</button>
                            <button type="button" data-emoji="👋" aria-label=":wave:">👋</button>
                            <button type="button" data-emoji="🙏" aria-label=":pray:">🙏</button>
                        </div>
                        <div class="input-group align-items-stretch">
                            <textarea id="chatMessageInput" class="form-control" rows="2" placeholder="Write a message…" aria-label="Chat message"></textarea>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-paper-plane me-1" aria-hidden="true"></i> Send
                            </button>
                        </div>
                        <small class="text-muted mt-1 d-block">Ctrl+Enter to send</small>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
