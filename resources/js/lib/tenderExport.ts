export type TenderExportFormat = 'csv' | 'xlsx';

type TenderExportPayload = {
    format: TenderExportFormat;
    scope: 'current' | 'selected' | 'run_new';
    tender_ids?: number[];
    query_id?: number;
    run_id?: number;
    filter_summary?: string;
};

export async function downloadTenderExport(
    payload: TenderExportPayload,
): Promise<void> {
    const response = await window.axios.post<Blob>(
        '/local/mvp/tenders/export',
        payload,
        { responseType: 'blob' },
    );
    const disposition = response.headers['content-disposition'] as string | undefined;
    const filename =
        disposition?.match(/filename="([^"]+)"/)?.[1] ??
        `tender-finder.${payload.format}`;
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
