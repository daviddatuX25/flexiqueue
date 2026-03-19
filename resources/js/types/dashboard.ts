export interface DashboardStats {
	active_program: { id: number; name: string } | null;
	program: { is_active: boolean; is_paused: boolean; is_running: boolean };
	sessions: {
		active: number;
		waiting: number;
		serving: number;
		completed_today: number;
		cancelled_today: number;
		no_show_today: number;
	};
	stations: { total: number; active: number; with_queue: number };
	stations_online: number;
	staff_online: number;
	by_track: Array<{ track_name: string; count: number }>;
}

export interface DashboardStation {
	id: number;
	name: string;
	is_active: boolean;
	queue_count: number;
	current_client: string | null;
	assigned_staff: Array<{ id: number; name: string; avatar_url?: string | null; availability_status?: string }>;
}
