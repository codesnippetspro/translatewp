export const managerStyles = `
.twp-admin-item.twp-admin-item,
.twp-admin-item.twp-admin-item :where(span),
.twp-toast[data-twp-manager="true"],
.twp-toast[data-twp-manager="true"] :where(span, button, a) {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    font-style: normal !important;
    text-transform: none !important;
    letter-spacing: normal !important;
}
.twp-admin-item.twp-admin-item {
    appearance: none;
    border-radius: 0 !important;
    font-size: 14px !important;
    font-weight: 400 !important;
    line-height: 20px !important;
}
.twp-admin-item.twp-admin-item :where(span) {
    font-size: inherit;
    font-weight: inherit;
    line-height: inherit;
    color: inherit;
}
.twp-toast[data-twp-manager="true"] {
    background: #fff !important;
    border: 1px solid #e2e2e4 !important;
    color: #1f2937 !important;
    font-size: 13px !important;
    font-weight: 400 !important;
    line-height: 1.4 !important;
}
.twp-toast[data-twp-manager="true"] :where(button, a) {
    all: revert;
    box-sizing: border-box;
    font: inherit;
    color: inherit;
    border-radius: 0;
}
`;
