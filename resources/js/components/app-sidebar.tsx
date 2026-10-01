import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Bell, BookOpen, Boxes, Building2, DoorOpen, FlaskConical, GraduationCap, LayoutGrid, Library, ListTree, Trophy, Users, Wallet } from 'lucide-react';
import AppLogo from './app-logo';

const moduleIcons = {
    dashboard: LayoutGrid,
    users: Users,
    schools: Building2,
    curricula: ListTree,
    academics: GraduationCap,
    promotions: GraduationCap,
    curriculum: BookOpen,
    finance: Wallet,
    library: Library,
    gate: DoorOpen,
    stores: Boxes,
    activities: Trophy,
    labs: FlaskConical,
    management: Bell,
    'school-setup': Building2,
} as const;

export function AppSidebar() {
    const { navigation = [] } = usePage<{ navigation?: { key: string; name: string; href: string; badge?: string }[] }>().props;
    const mainNavItems: NavItem[] = [
        ...navigation.map((module) => ({
            title: module.name,
            url: module.href,
            icon: moduleIcons[module.key as keyof typeof moduleIcons],
            badge: module.badge,
        })),
    ];

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
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
