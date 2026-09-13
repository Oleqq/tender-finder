export function scopedUrl(
    path: string,
    team: { id: number } | null | undefined,
): string {
    return team ? `${path}${path.includes('?') ? '&' : '?'}team_id=${team.id}` : path;
}
