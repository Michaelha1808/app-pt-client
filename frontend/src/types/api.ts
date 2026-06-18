export interface HealthResponse {
  status: "ok" | "error";
  timestamp: string;
  version: string;
  service: string;
}
