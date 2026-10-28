import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { CalendarDays, LayoutGrid, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Tableau de bord',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    { title: 'Rendez-vous', url: '/appointments', icon: CalendarDays },
    { title: 'Patients', url: '/patients', icon: Users },
    { title: 'Comptes du personnel', url: '/staff', icon: Users },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const permissions: Record<string, string> = {
        '/dashboard': 'dashboard.view',
        '/patients': 'patients.view',
        '/staff': 'users.manage',
        '/appointments': 'appointments.view',
    };
    const items = mainNavItems.filter((item) => auth.permissions.includes(permissions[item.url]));
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
